<?php
// CDN token-auth filesystem: identical to ObjectFS S3 except the external client
// is our subclass, which signs download redirects as CDN token-auth URLs.

namespace local_objectfs_cdntoken;

defined('MOODLE_INTERNAL') || die();

/**
 * Drop-in replacement for \tool_objectfs\s3_file_system.
 *
 * Everything (upload, verify, streams, presigned gating, range fallback) is
 * inherited unchanged; only the external client is swapped so that
 * generate_presigned_url() emits CDN token-auth URLs.
 */
class file_system extends \tool_objectfs\s3_file_system {

    /**
     * Return our CDN-signing client instead of the stock S3 client.
     *
     * @param object $config ObjectFS (tool_objectfs) config object.
     * @return \local_objectfs_cdntoken\client
     */
    protected function initialise_external_client($config) {
        return new \local_objectfs_cdntoken\client($config);
    }
}
