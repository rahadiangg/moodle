<?php
// Admin settings for local_objectfs_cdntoken.
// In the Helm deployment these are written by moosh during the configure Job;
// this page exists for completeness, defaults, and manual/admin-UI use.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_objectfs_cdntoken',
        get_string('pluginname', 'local_objectfs_cdntoken')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_objectfs_cdntoken/enabled',
        get_string('enabled', 'local_objectfs_cdntoken'),
        get_string('enabled_desc', 'local_objectfs_cdntoken'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_objectfs_cdntoken/cdndomain',
        get_string('cdndomain', 'local_objectfs_cdntoken'),
        get_string('cdndomain_desc', 'local_objectfs_cdntoken'),
        '',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configselect(
        'local_objectfs_cdntoken/cdnscheme',
        get_string('cdnscheme', 'local_objectfs_cdntoken'),
        get_string('cdnscheme_desc', 'local_objectfs_cdntoken'),
        'https',
        ['https' => 'https', 'http' => 'http']
    ));

    // Only Method A for now; extensible to other CDN token schemes later.
    $settings->add(new admin_setting_configselect(
        'local_objectfs_cdntoken/signingmethod',
        get_string('signingmethod', 'local_objectfs_cdntoken'),
        get_string('signingmethod_desc', 'local_objectfs_cdntoken'),
        'tokenA',
        ['tokenA' => get_string('signingmethod:tokenA', 'local_objectfs_cdntoken')]
    ));

    // Hash algorithm — must match the CDN's "Encryption Algorithm" setting.
    $settings->add(new admin_setting_configselect(
        'local_objectfs_cdntoken/algorithm',
        get_string('algorithm', 'local_objectfs_cdntoken'),
        get_string('algorithm_desc', 'local_objectfs_cdntoken'),
        'sha256',
        ['sha256' => 'SHA256', 'md5' => 'MD5']
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_objectfs_cdntoken/signingkey',
        get_string('signingkey', 'local_objectfs_cdntoken'),
        get_string('signingkey_desc', 'local_objectfs_cdntoken'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_objectfs_cdntoken/authparam',
        get_string('authparam', 'local_objectfs_cdntoken'),
        get_string('authparam_desc', 'local_objectfs_cdntoken'),
        'auth_key',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_objectfs_cdntoken/validity',
        get_string('validity', 'local_objectfs_cdntoken'),
        get_string('validity_desc', 'local_objectfs_cdntoken'),
        '1800',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_objectfs_cdntoken/uid',
        get_string('uid', 'local_objectfs_cdntoken'),
        get_string('uid_desc', 'local_objectfs_cdntoken'),
        '0',
        PARAM_ALPHANUMEXT
    ));
}
