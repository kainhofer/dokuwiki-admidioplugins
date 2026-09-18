<?php

/**
 * Default settings for the admidioplugins plugin
 */

$conf['cache_seconds'] = 900;        // how long clients and the server cache the catalogue
$conf['max_archive_mb'] = 32;        // same limit as Admidio's installer
$conf['fetch_timeout'] = 20;         // seconds for fetching a remote archive
$conf['allow_http'] = 0;             // allow archives from plain http:// addresses
$conf['allow_private_hosts'] = 0;    // allow archives from private/internal hosts (development only)
$conf['guard_struct_editor'] = 1;    // enforce page ACLs on struct's row editor (see action/guard.php)
$conf['canonical_lang'] = 'en';      // language namespace holding the plugin data; other languages show it
