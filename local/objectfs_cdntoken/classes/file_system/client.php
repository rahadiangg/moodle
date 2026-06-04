<?php
// Compatibility shim for ObjectFS's admin pages.
//
// ObjectFS's admin object-status / check / presigned-url-test pages resolve the
// client via \tool_objectfs\local\manager::get_client_classname_from_fs(), which
// string-derives the client class from the configured filesystem class as
// `<fs-without-_file_system>\client`. For our `\local_objectfs_cdntoken\file_system`
// that resolves to `\local_objectfs_cdntoken\file_system\client` (this class).
//
// Actual file serving does NOT use this path (it uses file_system::
// initialise_external_client()); this shim only makes the admin/monitoring pages
// work. It simply IS our real client.

namespace local_objectfs_cdntoken\file_system;

defined('MOODLE_INTERNAL') || die();

class client extends \local_objectfs_cdntoken\client {
}
