<?php

/**
 * DokuWiki Plugin admidioplugins (Action Component: struct editor guard)
 *
 * struct's aggregation editor ("add a row" below a struct serial/lookup table) checks on the
 * server only the schema's "allowed editors" - and skips its CSRF check for anonymous users. The
 * page ACL is enforced in JavaScript only. A plain POST to
 *   lib/exe/ajax.php?call=plugin_struct_aggregationeditor_save&schema=…&pid=…&entry[…]=…
 * therefore adds serial rows to any page, even anonymously, and …_delete removes any row by ID.
 *
 * This guard runs before struct's own handler and
 *  - refuses every aggregation editor or inline edit call for the two Admidio schemas (their rows are
 *    written only by this plugin's release form, which checks the page ACL and the archive);
 *  - for all other schemas, requires a valid security token and edit permission on the page the
 *    row belongs to (looked up by row ID for deletions), or a logged-in user for global data.
 *
 * @license GPL-2.0-or-later
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\struct\meta\Schema;

class action_plugin_admidioplugins_guard extends ActionPlugin
{
    private const PREFIX_EDITOR = 'plugin_struct_aggregationeditor_';
    private const PREFIX_INLINE = 'plugin_struct_inline_';

    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        // Run before struct's own AJAX handlers.
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'guard', null, -1000);
    }

    public function guard(Event $event, $param): void
    {
        global $INPUT;

        if (!$this->getConf('guard_struct_editor')) {
            return;
        }

        $call = (string)$event->data;
        $isEditor = str_starts_with($call, self::PREFIX_EDITOR);
        $isInline = str_starts_with($call, self::PREFIX_INLINE);
        if (!$isEditor && !$isInline) {
            return;
        }
        $operation = substr($call, strlen($isEditor ? self::PREFIX_EDITOR : self::PREFIX_INLINE));

        $schema = $this->requestedSchema($isEditor, $operation);
        $ours = [helper_plugin_admidioplugins::SCHEMA_PLUGIN, helper_plugin_admidioplugins::SCHEMA_RELEASE];

        if ($isEditor && in_array($schema, $ours, true)) {
            $this->deny($event, 'Rows of this schema are edited with the plugin release form.');
            return;
        }
        if ($isInline && $schema === helper_plugin_admidioplugins::SCHEMA_RELEASE) {
            $this->deny($event, 'Rows of this schema are edited with the plugin release form.');
            return;
        }

        // Only writing calls need more than struct checks itself.
        if (!in_array($operation, ['save', 'delete'], true)) {
            return;
        }

        if (!checkSecurityToken()) {
            $this->deny($event, 'Security token invalid.');
            return;
        }

        // struct's delete call sends only the row ID; the page the row belongs to is looked up.
        $pid = $isEditor && $operation === 'delete'
            ? $this->rowPage($schema, $INPUT->int('rid'))
            : cleanID($INPUT->str('pid'));

        if ($pid === null) {
            $this->deny($event, 'No such row.');
        } elseif ($pid !== '' && auth_quickaclcheck($pid) < AUTH_EDIT) {
            $this->deny($event, 'No permission to edit this page.');
        } elseif ($pid === '' && $INPUT->server->str('REMOTE_USER') === '') {
            // Global (lookup) data has no page whose permissions could apply.
            $this->deny($event, 'Please log in.');
        }
    }

    /**
     * The page a serial row belongs to: '' for global data, null if there is no such row.
     */
    private function rowPage(string $schema, int $rid): ?string
    {
        if ($rid <= 0) {
            return null;
        }
        try {
            $table = (new Schema($schema))->getTable();
            if ($table === '' || !(new Schema($schema))->getId()) {
                return null;
            }
            /** @var helper_plugin_struct_db $db */
            $db = plugin_load('helper', 'struct_db');
            $pid = $db->getDB()->queryValue("SELECT pid FROM data_$table WHERE rid = ? LIMIT 1", [$rid]);
        } catch (Throwable $ignored) {
            return null;
        }
        return $pid === null || $pid === false ? null : (string)$pid;
    }

    /**
     * The schema a struct editor call is about.
     */
    private function requestedSchema(bool $isEditor, string $operation): string
    {
        global $INPUT;

        if ($isEditor && $operation === 'new') {
            $searchconf = $INPUT->arr('searchconf');
            return (string)($searchconf['schemas'][0][0] ?? '');
        }
        if ($isEditor) {
            return $INPUT->str('schema');
        }

        // Inline edits name the field as "schema.column".
        $field = $INPUT->str('field');
        return str_contains($field, '.') ? substr($field, 0, strpos($field, '.')) : '';
    }

    private function deny(Event $event, string $message): void
    {
        $event->preventDefault();
        $event->stopPropagation();
        http_status(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
}
