<?php

/**
 * DokuWiki Plugin admidioplugins (Syntax Component: plugin info box)
 *
 *   {{admidioplugins>info}}
 *   {{admidioplugins>info en:plugins:someplugin}}
 *
 * Shows the plugin info box on a page that has no plugin data of its own, e.g. the German page
 * de:plugins:x of a plugin whose data is kept on en:plugins:x. On the page holding the data the box
 * is already shown in place of struct's table, so there this syntax renders nothing.
 *
 * @license GPL-2.0-or-later
 */

use dokuwiki\Extension\SyntaxPlugin;

class syntax_plugin_admidioplugins_info extends SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 150;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{admidioplugins>info(?:\s+[^}]*)?\}\}', $mode, 'plugin_admidioplugins_info');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return ['page' => trim(substr($match, strlen('{{admidioplugins>info'), -2))];
    }

    /** @inheritDoc */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $ID;

        if ($format === 'metadata') {
            // The box shows struct data, so the page cache has to depend on it.
            /** @var Doku_Renderer_metadata $renderer */
            $renderer->meta['plugin']['admidioplugins']['releases'] = true;
            return true;
        }
        if ($format !== 'xhtml') {
            return false;
        }

        /** @var helper_plugin_admidioplugins $helper */
        $helper = plugin_load('helper', 'admidioplugins');
        if (!$helper->isReady()) {
            return true;
        }

        $source = $helper->sourcePage((string)$ID, (string)($data['page'] ?? ''));
        if ($source === (string)$ID || !$helper->hasPluginData($source)) {
            return true;
        }

        $renderer->doc .= $helper->renderInfoBox($helper->getPluginData($source), $helper->getReleases($source));
        return true;
    }
}
