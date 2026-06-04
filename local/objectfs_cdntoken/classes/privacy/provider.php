<?php
// Privacy provider: this plugin stores no personal data.

namespace local_objectfs_cdntoken\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Null privacy provider — the plugin only signs CDN URLs and stores no user data.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Reason this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
