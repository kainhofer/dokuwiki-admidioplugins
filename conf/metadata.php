<?php

/**
 * Options for the admidioplugins plugin
 */

$meta['cache_seconds'] = ['numeric', '_min' => 60];
$meta['max_archive_mb'] = ['numeric', '_min' => 1];
$meta['fetch_timeout'] = ['numeric', '_min' => 5];
$meta['allow_http'] = ['onoff'];
$meta['allow_private_hosts'] = ['onoff'];
$meta['guard_struct_editor'] = ['onoff'];
$meta['canonical_lang'] = ['string', '_pattern' => '/^[a-z]{2}(-[a-z]+)?$/'];
