<?php
// Provider-neutral CDN token-auth (Method A) signed-URL delivery for ObjectFS.
// Subclasses tool_objectfs to redirect file downloads through a CDN that signs
// URLs with an auth_key the edge validates (Huawei/Alibaba/Tencent Method A).

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_objectfs_cdntoken';
$plugin->version   = 2026060400;
$plugin->requires  = 2024100700;   // Moodle 4.5.0
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';
// Hard dependency: our classes extend tool_objectfs classes, which must exist.
$plugin->dependencies = [
    'tool_objectfs' => ANY_VERSION,
];
