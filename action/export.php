<?php

/**
 * DokuWiki Plugin admidioplugins (Action Component: catalogue export)
 *
 * Serves the struct data of the plugin pages as the catalogue Admidio's plugin manager reads
 * (format 1, see README.md and the catalogue specification).
 *
 * Endpoint:
 *   doku.php?do=admidioplugins
 *   doku.php?do=admidioplugins&format=1&admidio=5.1.0&php=8.3.6&channel=stable&releases=latest
 *
 * Every parameter is optional. Without any, the full catalogue with every release is returned.
 *
 * @license GPL-2.0-or-later
 */

use dokuwiki\Cache\Cache;
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Logger;

class action_plugin_admidioplugins_export extends ActionPlugin
{
    public const ACTION = 'admidioplugins';

    /** The catalogue formats this endpoint can produce. */
    public const FORMATS = [1];

    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAction');
    }

    /**
     * Answer ?do=admidioplugins with the catalogue instead of rendering a page.
     */
    public function handleAction(Event $event, $param): void
    {
        if ($event->data !== self::ACTION) {
            return;
        }

        $event->preventDefault();
        $event->stopPropagation();

        try {
            $request = $this->parseRequest();
            $body = $this->getCatalogueJson($request);
            $this->send($body);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, 'invalid_request', $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('admidioplugins: catalogue export failed: ' . $e->getMessage(), $e->getTraceAsString(), $e->getFile(), $e->getLine());
            $this->sendError(500, 'repository_error', 'The plugin catalogue could not be built.');
        }

        exit;
    }

    /**
     * Read and validate the request parameters. All of them are optional.
     *
     * @return array{format:int,admidio:string,php:string,channel:string,releases:string,plugin:string[]}
     */
    private function parseRequest(): array
    {
        global $INPUT;

        $format = trim($INPUT->str('format', '1'));
        if (!ctype_digit($format) || !in_array((int)$format, self::FORMATS, true)) {
            throw new InvalidArgumentException(
                "Unsupported format '$format'. Supported: " . implode(', ', self::FORMATS) . '.'
            );
        }

        $admidio = trim($INPUT->str('admidio'));
        $php = trim($INPUT->str('php'));
        foreach (['admidio' => $admidio, 'php' => $php] as $name => $version) {
            if ($version !== '' && !helper_plugin_admidioplugins::isValidVersion($version)) {
                throw new InvalidArgumentException("Invalid $name version '$version'.");
            }
        }

        $channel = strtolower(trim($INPUT->str('channel')));
        $aliases = ['final' => 'stable', 'dev' => 'alpha', 'development' => 'alpha', '' => 'all'];
        $channel = $aliases[$channel] ?? $channel;
        if ($channel !== 'all' && !isset(helper_plugin_admidioplugins::CHANNELS[$channel])) {
            throw new InvalidArgumentException(
                "Unsupported channel '$channel'. Allowed: all, "
                . implode(', ', array_keys(helper_plugin_admidioplugins::CHANNELS)) . '.'
            );
        }

        $releases = strtolower(trim($INPUT->str('releases', 'all')));
        if (!in_array($releases, ['all', 'latest'], true)) {
            throw new InvalidArgumentException("Unsupported releases '$releases'. Allowed: all, latest.");
        }

        $plugins = [];
        foreach (preg_split('/\s*,\s*/', trim($INPUT->str('plugin')), -1, PREG_SPLIT_NO_EMPTY) as $id) {
            if (!helper_plugin_admidioplugins::isValidId($id)) {
                throw new InvalidArgumentException("Invalid plugin id '$id'.");
            }
            $plugins[] = $id;
        }
        sort($plugins);

        return [
            'format' => (int)$format,
            'admidio' => $admidio,
            'php' => $php,
            'channel' => $channel,
            'releases' => $releases,
            'plugin' => array_values(array_unique($plugins)),
        ];
    }

    /**
     * The catalogue as JSON, from the cache while no plugin data changed since it was built.
     */
    private function getCatalogueJson(array $request): string
    {
        /** @var helper_plugin_admidioplugins $helper */
        $helper = plugin_load('helper', 'admidioplugins');
        if (!$helper->isReady()) {
            throw new RuntimeException('The struct plugin or the admidio_plugin schemas are missing.');
        }

        $cache = new Cache('admidioplugins:' . json_encode($request), '.admidioplugins.json');
        $depends = ['files' => [__FILE__, dirname(__DIR__) . '/helper.php', DOKU_CONF . 'local.php']];
        $dbFile = $helper->getStructDbFile();
        if ($dbFile !== null) {
            $depends['files'][] = $dbFile;
        }
        $depends['age'] = max(60, (int)$this->getConf('cache_seconds'));

        if ($cache->useCache($depends)) {
            return $cache->retrieveCache();
        }

        $body = json_encode(
            $this->buildCatalogue($helper, $request, $dbFile),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $cache->storeCache($body);

        return $body;
    }

    /**
     * Build the catalogue from the struct data.
     */
    private function buildCatalogue(helper_plugin_admidioplugins $helper, array $request, ?string $dbFile): array
    {
        $plugins = $this->collectPlugins($helper, $request['plugin']);

        $releases = [];
        foreach ($helper->getReleases() as $row) {
            $pid = $row['pid'];
            if (!isset($plugins[$pid])) {
                continue;
            }
            $release = $this->buildRelease($helper, $row);
            if ($release === null || !$this->acceptRelease($release, $request)) {
                continue;
            }
            $releases[$pid][] = $release;
        }

        $catalogue = [];
        foreach ($plugins as $pid => $plugin) {
            $list = $releases[$pid] ?? [];
            if ($request['releases'] === 'latest') {
                $list = $this->latestPerStatus($list);
            }
            if ($list === []) {
                continue;
            }
            usort($list, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
            $plugin['releases'] = $list;
            $catalogue[] = $plugin;
        }

        usort($catalogue, static fn(array $a, array $b): int => strnatcasecmp($a['id'], $b['id']));

        $filters = array_filter([
            'admidio' => $request['admidio'],
            'php' => $request['php'],
            'channel' => $request['channel'] === 'all' ? '' : $request['channel'],
            'releases' => $request['releases'] === 'all' ? '' : $request['releases'],
            'plugin' => implode(',', $request['plugin']),
        ], static fn($value): bool => $value !== '');

        $result = [
            'format' => $request['format'],
            'updated' => gmdate('Y-m-d\TH:i:s\Z', $dbFile !== null ? (int)@filemtime($dbFile) : time()),
            'directory' => DOKU_URL,
        ];
        if ($filters !== []) {
            $result['filters'] = $filters;
        }
        $result['plugins'] = $catalogue;

        return $result;
    }

    /**
     * The plugins that may appear in the catalogue, keyed by page ID, without their releases.
     *
     * A plugin ID claimed by more than one page belongs to the page that was created first; the
     * others are left out. Otherwise a second page could publish "updates" of somebody else's plugin.
     */
    private function collectPlugins(helper_plugin_admidioplugins $helper, array $onlyIds): array
    {
        $candidates = [];
        foreach ($helper->getAllPlugins() as $pid => $data) {
            $id = trim((string)($data['plugin_id'] ?? ''));
            if (!helper_plugin_admidioplugins::isValidId($id)) {
                continue;
            }
            if ($onlyIds !== [] && !in_array($id, $onlyIds, true)) {
                continue;
            }
            $status = strtolower(trim((string)($data['plugin_status'] ?? '')));
            if (in_array($status, helper_plugin_admidioplugins::HIDDEN_PLUGIN_STATUSES, true)) {
                continue;
            }
            // The catalogue is public: only pages anonymous visitors may read are part of it,
            // whoever happens to request (and so fill the cache).
            if (auth_aclcheck($pid, '', []) < AUTH_READ) {
                continue;
            }
            $created = (int)p_get_metadata($pid, 'date created', METADATA_DONT_RENDER);
            $candidates[$id][] = ['pid' => $pid, 'created' => $created ?: PHP_INT_MAX, 'data' => $data];
        }

        $plugins = [];
        foreach ($candidates as $id => $claims) {
            usort($claims, static fn(array $a, array $b): int => [$a['created'], $a['pid']] <=> [$b['created'], $b['pid']]);
            if (count($claims) > 1) {
                Logger::debug("admidioplugins: plugin id '$id' is claimed by several pages; using {$claims[0]['pid']}");
            }
            $plugins[$claims[0]['pid']] = $this->buildPlugin($id, $claims[0]['pid'], $claims[0]['data']);
        }

        return $plugins;
    }

    /**
     * One plugin entry, without releases. Optional keys are left out when empty.
     */
    private function buildPlugin(string $id, string $pid, array $data): array
    {
        $text = static fn(string $key): string => trim((string)($data[$key] ?? ''));

        $description = array_filter([
            'en' => $text('description'),
            'de' => $text('description_de'),
        ], static fn(string $value): bool => $value !== '');

        $tags = $data['tags'] ?? [];
        $tags = is_array($tags) ? $tags : preg_split('/\s*[,;\n]\s*/u', (string)$tags, -1, PREG_SPLIT_NO_EMPTY);
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags), 'strlen')));

        $plugin = [
            'id' => $id,
            'name' => $text('name') !== '' ? $text('name') : $id,
            'description' => $description,
            'author' => $text('author'),
            'url' => wl($pid, '', true, '&'),
            'homepage' => $text('homepage'),
            'source' => $text('repository'),
            'license' => $text('license'),
            'icon' => $text('icon'),
            'category' => $text('category'),
            'tags' => $tags,
            'status' => strtolower($text('plugin_status')) ?: 'active',
        ];

        return array_filter($plugin, static fn($value): bool => $value !== '' && $value !== []);
    }

    /**
     * One release entry, or null when the row cannot be offered at all.
     */
    private function buildRelease(helper_plugin_admidioplugins $helper, array $row): ?array
    {
        $version = trim((string)$row['version']);
        $download = $helper->publicDownloadUrl((string)$row['download']);
        if (!helper_plugin_admidioplugins::isValidVersion($version) || $download === null) {
            return null;
        }

        // Only releases whose archive was checked - by the release form or setup --derive, which
        // record its checksum - are offered for installation. Rows imported from the old pages
        // (plugins for the pre-5.1 runtime) are listed on the wiki pages, never in the catalogue.
        $sha256 = strtolower(trim((string)$row['sha256']));
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return null;
        }

        $requires = array_filter([
            'admidio' => trim((string)$row['requires_admidio']),
            'php' => trim((string)$row['requires_php']),
        ], static fn(string $value): bool => $value !== '');

        $size = trim((string)$row['size']);

        $release = [
            'version' => $version,
            'status' => helper_plugin_admidioplugins::normalizeStatus((string)$row['release_status']),
            'date' => $this->isoDate((string)$row['release_date']),
            'requires' => $requires,
            'download' => $download,
            'sha256' => $sha256,
            'size' => ctype_digit($size) ? (int)$size : '',
            'notes' => trim((string)$row['notes']),
        ];

        return array_filter($release, static fn($value): bool => $value !== '' && $value !== []);
    }

    /**
     * Whether a release passes the requested filters.
     */
    private function acceptRelease(array $release, array $request): bool
    {
        if ($request['channel'] !== 'all'
            && !in_array($release['status'], helper_plugin_admidioplugins::CHANNELS[$request['channel']], true)) {
            return false;
        }
        if ($request['admidio'] !== ''
            && !helper_plugin_admidioplugins::versionMatches($request['admidio'], $release['requires']['admidio'] ?? '')) {
            return false;
        }
        if ($request['php'] !== ''
            && !helper_plugin_admidioplugins::versionMatches($request['php'], $release['requires']['php'] ?? '')) {
            return false;
        }
        return true;
    }

    /**
     * Keep the newest release of each status.
     */
    private function latestPerStatus(array $releases): array
    {
        $latest = [];
        foreach ($releases as $release) {
            $status = $release['status'];
            if (!isset($latest[$status]) || version_compare($release['version'], $latest[$status]['version'], '>')) {
                $latest[$status] = $release;
            }
        }
        return array_values($latest);
    }

    /**
     * A struct date as YYYY-MM-DD, or '' when it is not a date.
     */
    private function isoDate(string $value): string
    {
        if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})/', trim($value), $match)) {
            return "$match[1]-$match[2]-$match[3]";
        }
        return '';
    }

    private function send(string $body): void
    {
        global $INPUT;

        $etag = '"' . md5($body) . '"';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=' . max(60, (int)$this->getConf('cache_seconds')));
        header('ETag: ' . $etag);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');

        if (trim($INPUT->server->str('HTTP_IF_NONE_MATCH')) === $etag) {
            http_response_code(304);
            return;
        }

        http_response_code(200);
        echo $body;
    }

    private function sendError(int $status, string $code, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
