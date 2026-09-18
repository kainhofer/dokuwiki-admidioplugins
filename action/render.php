<?php

/**
 * DokuWiki Plugin admidioplugins (Action Component: page rendering)
 *
 *  - replaces struct's default table for the admidio_plugin page data by the plugin info box;
 *  - makes pages showing plugin data depend on the struct database in the page cache;
 *  - tells the browser whether the current user may manage releases on this page.
 *
 * @license GPL-2.0-or-later
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\struct\meta\Assignments;

class action_plugin_admidioplugins_render extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PLUGIN_STRUCT_RENDER_SCHEMA_DATA', 'BEFORE', $this, 'renderPluginInfo');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'addCacheDependency');
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addJsInfo');
    }

    /**
     * Render the admidio_plugin page data with our template instead of struct's table.
     */
    public function renderPluginInfo(Event $event, $param): void
    {
        $schemadata = $event->data['schemadata'];
        if ($schemadata->getSchema()->getTable() !== helper_plugin_admidioplugins::SCHEMA_PLUGIN) {
            return;
        }
        if ($event->data['format'] !== 'xhtml') {
            return; // other renderers (e.g. dw2pdf, metadata) get struct's default
        }

        $event->preventDefault();

        $data = $schemadata->getDataArray();
        if (!array_filter($data, static fn($value): bool => $value !== '' && $value !== [])) {
            return;
        }

        /** @var helper_plugin_admidioplugins $helper */
        $helper = plugin_load('helper', 'admidioplugins');
        $releases = $helper->getReleases($schemadata->getPid());

        $event->data['renderer']->doc .= $helper->renderInfoBox($data, $releases);
        $event->data['hasdata'] = true;
    }

    /**
     * Pages with plugin data or a release list change whenever struct data changes.
     */
    public function addCacheDependency(Event $event, $param): void
    {
        /** @var \dokuwiki\Cache\CacheParser $cache */
        $cache = $event->data;
        if ($cache->mode !== 'xhtml' || !$cache->page) {
            return;
        }

        // p_get_metadata() resolves two key levels only, so the plugin's entry is read as a whole.
        $meta = p_get_metadata($cache->page, 'plugin admidioplugins', METADATA_DONT_RENDER);
        $hasReleases = !empty($meta['releases']);
        $hasInfo = false;
        if (!$hasReleases) {
            try {
                $hasInfo = in_array(
                    helper_plugin_admidioplugins::SCHEMA_PLUGIN,
                    Assignments::getInstance()->getPageAssignments($cache->page),
                    true
                );
            } catch (Throwable $ignored) {
                return;
            }
        }
        if (!$hasReleases && !$hasInfo) {
            return;
        }

        /** @var helper_plugin_admidioplugins $helper */
        $helper = plugin_load('helper', 'admidioplugins');
        $dbFile = $helper->getStructDbFile();
        if ($dbFile !== null) {
            $cache->depends['files'][] = $dbFile;
        }
    }

    /**
     * Whether the release controls are offered is decided in the browser, because the rendered
     * page is cached for all users. The server checks the permission again on every call.
     */
    public function addJsInfo(Event $event, $param): void
    {
        global $JSINFO, $ID, $INPUT;

        // The pages whose releases this page lists: its own, or those it shows from other pages
        // (de:plugins:x showing en:plugins:x). Releases are edited on those pages.
        $editable = [];
        if ($ID && $INPUT->server->str('REMOTE_USER') !== '') {
            $meta = p_get_metadata($ID, 'plugin admidioplugins', METADATA_DONT_RENDER);
            $sources = $meta['sources'] ?? [];
            $pages = array_unique(array_merge([$ID], is_array($sources) ? array_keys($sources) : []));
            foreach ($pages as $page) {
                if (auth_quickaclcheck($page) >= AUTH_EDIT) {
                    $editable[] = $page;
                }
            }
        }

        $JSINFO['plugins']['admidioplugins'] = [
            'editable' => $editable,
            'sectok' => $editable ? getSecurityToken() : '',
        ];
    }
}
