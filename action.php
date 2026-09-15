<?php
/**
 * DokuWiki Plugin admidio_repository (Action Component)
 *
 * Exposes a JSON repository endpoint backed by the Struct plugin.
 *
 * Endpoint:
 *   doku.php?do=admidio_repository&admidio=5.1.5&php=8.2.12&channel=stable
 *
 * @license GPL-2.0-or-later
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\plugin\struct\meta\ConfigParser;
use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;

class action_plugin_admidioplugins extends ActionPlugin
{
    private const ACTION = 'admidioplugins';
    private const REPOSITORY_VERSION = 1;

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller): void
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAction');
    }

    /**
     * Handle ?do=admidio_repository before DokuWiki renders a normal page.
     */
    public function handleAction(Doku_Event $event, $param): void
    {
        if ($event->data !== self::ACTION) {
            return;
        }

        $event->preventDefault();
        $event->stopPropagation();

        try {
            $request = $this->parseRequest();
            $rows = $this->loadStructRows();
            $payload = $this->buildRepository($rows, $request);
            $this->sendJson($payload, 200);
        } catch (InvalidArgumentException $e) {
            $this->sendJson([
                'error' => 'invalid_request',
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            $this->sendJson([
                'error' => 'repository_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        exit;
    }

    /**
     * Parse and validate request parameters.
     *
     * Required:
     *   admidio  installed Admidio version, e.g. 5.1.5
     *   php      installed PHP version, e.g. 8.2.12
     *
     * Optional:
     *   channel  stable|beta|development (default stable)
     *   plugin   restrict result to a single plugin_id
     *
     * @return array{admidio:string,php:string,channel:string,plugin:string}
     */
    private function parseRequest(): array
    {
        global $INPUT;

        $admidio = trim($INPUT->str('admidio'));
        $php = trim($INPUT->str('php'));
        $channel = strtolower(trim($INPUT->str('channel', 'stable')));
        $plugin = trim($INPUT->str('plugin'));

        if ($admidio === '') {
            throw new InvalidArgumentException("Missing required parameter 'admidio'.");
        }
        if ($php === '') {
            throw new InvalidArgumentException("Missing required parameter 'php'.");
        }

        $this->assertVersion($admidio, 'admidio');
        $this->assertVersion($php, 'php');

        if ($channel === 'final') {
            $channel = 'stable';
        }
        if ($channel === 'dev') {
            $channel = 'development';
        }

        if (!in_array($channel, ['stable', 'beta', 'development'], true)) {
            throw new InvalidArgumentException(
                "Unsupported channel '$channel'. Allowed values: stable, beta, development."
            );
        }

        if ($plugin !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $plugin)) {
            throw new InvalidArgumentException("Invalid plugin id '$plugin'.");
        }

        return [
            'admidio' => $admidio,
            'php' => $php,
            'channel' => $channel,
            'plugin' => $plugin,
        ];
    }

    /**
     * Accept normal dotted versions and PHP/version_compare prerelease suffixes.
     */
    private function assertVersion(string $version, string $parameter): void
    {
        if (!preg_match('/^\d+(?:\.\d+){0,4}(?:[-+._]?[0-9A-Za-z][0-9A-Za-z._-]*)?$/', $version)) {
            throw new InvalidArgumentException("Invalid $parameter version '$version'.");
        }
    }

    /**
     * Query the two Struct schemas using Struct's own ConfigParser/SearchConfig API.
     *
     * We deliberately do not access struct.sqlite3 directly.
     *
     * @return array<int,array<string,string>>
     */
    private function loadStructRows(): array
    {
        if (!plugin_load('helper', 'struct')) {
            throw new RuntimeException("Required DokuWiki plugin 'struct' is not installed or enabled.");
        }

        $lines = [
            'schema: admidio_plugin, admidio_plugin_release',
            'cols: %pageid%, '
                . 'admidio_plugin.plugin_id, admidio_plugin.name, admidio_plugin.description, '
                . 'admidio_plugin.author, admidio_plugin.homepage, admidio_plugin.repository, '
                . 'admidio_plugin.license, admidio_plugin.category, admidio_plugin.plugin_status, '
                . 'admidio_plugin.tags, '
                . 'admidio_plugin_release.version, admidio_plugin_release.release_date, '
                . 'admidio_plugin_release.admidio_min, admidio_plugin_release.admidio_max, '
                . 'admidio_plugin_release.php_min, admidio_plugin_release.php_max, '
                . 'admidio_plugin_release.download, admidio_plugin_release.sha256, '
                . 'admidio_plugin_release.release_status, admidio_plugin_release.notes',
            'sort: admidio_plugin.plugin_id',
        ];

        try {
            $parser = new ConfigParser($lines);
            $config = $parser->getConfig();
            $search = new SearchConfig($config);
            $results = $search->getRows();
        } catch (StructException $e) {
            // php_max is intentionally optional during the prototype. Retry without it.
            $lines[1] = str_replace(', admidio_plugin_release.php_max', '', $lines[1]);
            try {
                $parser = new ConfigParser($lines);
                $config = $parser->getConfig();
                $search = new SearchConfig($config);
                $results = $search->getRows();
            } catch (StructException $e2) {
                throw new RuntimeException('Struct query failed: ' . $e2->getMessage(), 0, $e2);
            }
        }

        $data = [];
        /** @var Value[] $rowValues */
        foreach ($results as $rowValues) {
            $row = [];
            foreach ($rowValues as $value) {
                $key = $value->getColumn()->getFullQualifiedLabel();
                // getDisplayValue() is also what Struct's public remote aggregation API returns.
                $row[$key] = trim((string)$value->getDisplayValue());
            }
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Build one best compatible release per plugin.
     *
     * @param array<int,array<string,string>> $rows
     * @param array{admidio:string,php:string,channel:string,plugin:string} $request
     */
    private function buildRepository(array $rows, array $request): array
    {
        $selected = [];

        foreach ($rows as $row) {
            $pluginId = $this->rowValue($row, 'admidio_plugin.plugin_id');
            $version = $this->rowValue($row, 'admidio_plugin_release.version', 'version');

            if ($pluginId === '' || $version === '') {
                continue;
            }
            if ($request['plugin'] !== '' && strcasecmp($request['plugin'], $pluginId) !== 0) {
                continue;
            }

            $pluginStatus = strtolower($this->rowValue($row, 'admidio_plugin.plugin_status', 'plugin_status'));
            if (in_array($pluginStatus, ['archived', 'unmaintained'], true)) {
                continue;
            }

            $releaseStatus = strtolower($this->rowValue(
                $row,
                'admidio_plugin_release.release_status',
                'release_status'
            ));
            if (!$this->channelAllows($request['channel'], $releaseStatus)) {
                continue;
            }

            $admidioMin = $this->rowValue($row, 'admidio_plugin_release.admidio_min', 'admidio_min');
            $admidioMax = $this->rowValue($row, 'admidio_plugin_release.admidio_max', 'admidio_max');
            $phpMin = $this->rowValue($row, 'admidio_plugin_release.php_min', 'php_min');
            $phpMax = $this->rowValue($row, 'admidio_plugin_release.php_max', 'php_max');

            if (!$this->isCompatible($request['admidio'], $admidioMin, $admidioMax)) {
                continue;
            }
            if (!$this->isCompatible($request['php'], $phpMin, $phpMax)) {
                continue;
            }

            $candidate = $this->rowToPlugin($row);

            if (!isset($selected[$pluginId])) {
                $selected[$pluginId] = $candidate;
                continue;
            }

            $currentVersion = $selected[$pluginId]['release']['version'];
            $comparison = version_compare($version, $currentVersion);

            if ($comparison > 0) {
                $selected[$pluginId] = $candidate;
            } elseif ($comparison === 0) {
                // Deterministic tie-breaker if malformed data contains a duplicate version.
                $candidateDate = $candidate['release']['release_date'] ?? '';
                $currentDate = $selected[$pluginId]['release']['release_date'] ?? '';
                if ($candidateDate > $currentDate) {
                    $selected[$pluginId] = $candidate;
                }
            }
        }

        ksort($selected, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'repository_version' => self::REPOSITORY_VERSION,
            'generated_at' => gmdate('c'),
            'environment' => [
                'admidio' => $request['admidio'],
                'php' => $request['php'],
                'channel' => $request['channel'],
            ],
            'plugins' => array_values($selected),
        ];
    }

    /**
     * Map a joined Struct row to the public repository representation.
     */
    private function rowToPlugin(array $row): array
    {
        $pageId = $this->rowValue($row, '%pageid%');
        $download = $this->rowValue($row, 'admidio_plugin_release.download', 'download');

        return [
            'id' => $this->rowValue($row, 'admidio_plugin.plugin_id'),
            'name' => $this->rowValue($row, 'admidio_plugin.name'),
            'description' => $this->rowValue($row, 'admidio_plugin.description'),
            'author' => $this->rowValue($row, 'admidio_plugin.author'),
            'homepage' => $this->nullIfEmpty($this->rowValue($row, 'admidio_plugin.homepage')),
            'repository' => $this->nullIfEmpty($this->rowValue($row, 'admidio_plugin.repository')),
            'license' => $this->nullIfEmpty($this->rowValue($row, 'admidio_plugin.license')),
            'category' => $this->nullIfEmpty($this->rowValue($row, 'admidio_plugin.category')),
            'status' => $this->nullIfEmpty($this->rowValue($row, 'admidio_plugin.plugin_status', 'plugin_status')),
            'tags' => $this->parseTags($this->rowValue($row, 'admidio_plugin.tags', 'tags')),
            'page' => $pageId !== '' ? wl($pageId, '', true, '&') : null,
            'release' => [
                'version' => $this->rowValue($row, 'admidio_plugin_release.version', 'version'),
                'release_date' => $this->nullIfEmpty(
                    $this->rowValue($row, 'admidio_plugin_release.release_date', 'release_date')
                ),
                'status' => $this->nullIfEmpty(
                    $this->rowValue($row, 'admidio_plugin_release.release_status', 'release_status')
                ),
                'requires' => [
                    'admidio' => [
                        'min' => $this->nullIfEmpty(
                            $this->rowValue($row, 'admidio_plugin_release.admidio_min', 'admidio_min')
                        ),
                        'max' => $this->nullIfEmpty(
                            $this->rowValue($row, 'admidio_plugin_release.admidio_max', 'admidio_max')
                        ),
                    ],
                    'php' => [
                        'min' => $this->nullIfEmpty(
                            $this->rowValue($row, 'admidio_plugin_release.php_min', 'php_min')
                        ),
                        'max' => $this->nullIfEmpty(
                            $this->rowValue($row, 'admidio_plugin_release.php_max', 'php_max')
                        ),
                    ],
                ],
                'download' => $this->downloadUrl($download),
                'sha256' => $this->nullIfEmpty(
                    $this->rowValue($row, 'admidio_plugin_release.sha256', 'sha256')
                ),
                'notes' => $this->nullIfEmpty(
                    $this->rowValue($row, 'admidio_plugin_release.notes', 'notes')
                ),
            ],
        ];
    }

    /**
     * Compatibility interval is inclusive. Empty minimum/maximum is unbounded.
     */
    private function isCompatible(string $installed, string $minimum, string $maximum): bool
    {
        if ($minimum !== '' && version_compare($installed, $minimum, '<')) {
            return false;
        }
        if ($maximum !== '' && version_compare($installed, $maximum, '>')) {
            return false;
        }
        return true;
    }

    /**
     * Which release statuses are admitted by a client channel.
     */
    private function channelAllows(string $channel, string $releaseStatus): bool
    {
        $status = strtolower(trim($releaseStatus));

        // Be conservative if status is missing or unknown.
        if ($status === '' || $status === 'final') {
            $status = 'stable';
        }

        $allowed = [
            'stable' => ['stable'],
            'beta' => ['stable', 'rc', 'beta'],
            'development' => ['stable', 'rc', 'beta', 'alpha', 'development', 'dev'],
        ];

        return in_array($status, $allowed[$channel], true);
    }

    /**
     * Resolve a value from the exact Struct label, with optional fallbacks.
     */
    private function rowValue(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return trim((string)$row[$key]);
            }
        }

        // Struct special columns can vary slightly by version. As a final
        // fallback, accept a unique key ending in ".<field>".
        foreach ($keys as $key) {
            if (str_contains($key, '.')) {
                $suffix = '.' . substr($key, strrpos($key, '.') + 1);
                $matches = [];
                foreach ($row as $rowKey => $value) {
                    if (str_ends_with((string)$rowKey, $suffix)) {
                        $matches[] = trim((string)$value);
                    }
                }
                if (count($matches) === 1) {
                    return $matches[0];
                }
            }
        }

        return '';
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Struct Tag/Multi fields are display strings in aggregation output.
     * Handle the common comma/semicolon/newline separators conservatively.
     *
     * @return string[]
     */
    private function parseTags(string $tags): array
    {
        if ($tags === '') {
            return [];
        }
        $parts = preg_split('/\s*[,;\n]\s*/u', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_map('trim', $parts)));
    }

    /**
     * Convert a Struct download value into a public URL.
     *
     * Supports:
     *   - full http(s) URL
     *   - DokuWiki media id: en:plugins:downloads:file.zip
     *   - DokuWiki media syntax: {{en:plugins:downloads:file.zip|file.zip}}
     */
    private function downloadUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        if (preg_match('/^\{\{\s*([^?|}\s]+)(?:\?[^|}]*)?(?:\|[^}]*)?\s*\}\}$/u', $value, $match)) {
            $value = $match[1];
        }

        $value = ltrim($value, ':');
        return ml($value, '', true, '&');
    }

    private function sendJson(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        echo "\n";
    }
}
