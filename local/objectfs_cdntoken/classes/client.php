<?php
// CDN token-auth S3 client: inherits all S3 behaviour, but overrides the
// presigned-URL generation to produce a CDN Method-A token-auth URL.

namespace local_objectfs_cdntoken;

defined('MOODLE_INTERNAL') || die();

use tool_objectfs\local\store\signed_url;

/**
 * Extends the ObjectFS S3 client. Uploads/verify/streams are unchanged (they
 * still talk to the private object-storage bucket via the AWS SDK). Only the
 * URL the browser is redirected to for downloads is replaced with a CDN
 * token-auth (Method A) signed URL.
 *
 * MUST be named exactly \local_objectfs_cdntoken\client: tool_objectfs'
 * manager::get_client_classname_from_fs() string-derives this from the
 * configured filesystem class for its admin test/check pages.
 */
class client extends \tool_objectfs\local\store\s3\client {

    /**
     * Build a CDN Method-A signed URL for the given content hash.
     *
     * Method A: sstring = "<Filename>-<ts>-<rand>-<uid>-<key>",
     *           auth_key  = "<ts>-<rand>-<uid>-md5(sstring)",
     *           URL       = "<scheme>://<domain><Filename>?<authparam>=<auth_key>".
     * Filename is the URI path only (object key, leading slash, no query).
     *
     * On misconfiguration this throws; the caller
     * (object_file_system::redirect_to_presigned_url) catches it and falls back
     * to streaming the file through PHP, so downloads never hard-fail.
     *
     * @param string $contenthash
     * @param array $headers Ignored here (no per-request response headers under token auth).
     * @return signed_url
     */
    public function generate_presigned_url($contenthash, $headers = []) {
        $cfg = get_config('local_objectfs_cdntoken');

        $domain = isset($cfg->cdndomain) ? rtrim(trim($cfg->cdndomain), '/') : '';
        $key = isset($cfg->signingkey) ? (string)$cfg->signingkey : '';
        if ($domain === '' || $key === '') {
            throw new \coding_exception(
                'local_objectfs_cdntoken: cdndomain and signingkey must be configured');
        }

        $scheme = !empty($cfg->cdnscheme) ? $cfg->cdnscheme : 'https';
        $authparam = !empty($cfg->authparam) ? $cfg->authparam : 'auth_key';
        $validity = isset($cfg->validity) ? (int)$cfg->validity : 1800;
        if ($validity < 60) {
            $validity = 1800;
        }
        $uid = (isset($cfg->uid) && $cfg->uid !== '') ? (string)$cfg->uid : '0';
        $rand = '0';
        // Hash algorithm must match the CDN's "Encryption Algorithm" setting.
        $algo = (isset($cfg->algorithm) && $cfg->algorithm === 'md5') ? 'md5' : 'sha256';

        // Object key path the CDN serves, e.g. /aa/bb/<sha1> (with optional prefix).
        // bucketkeyprefix + get_filepath_from_hash() are inherited from the S3 client.
        $filename = self::normalize_filename(
            $this->bucketkeyprefix, $this->get_filepath_from_hash($contenthash));

        $ts = time();
        $authvalue = self::methoda_authkey($filename, $key, $uid, $rand, $ts, $algo);

        // Build via params array so the value is encoded correctly; hex path needs no encoding.
        $url = new \moodle_url($scheme . '://' . $domain . $filename, [$authparam => $authvalue]);

        return new signed_url($url, $ts + $validity);
    }

    /**
     * Build the CDN object path (the URI the token is signed over).
     *
     * Pure string logic (no Moodle deps) so it is unit-testable in isolation.
     *
     * @param string $prefix   Bucket key prefix (may be '' or e.g. 'moodle/').
     * @param string $filepath ObjectFS filepath-from-hash, e.g. aa/bb/<sha1>.
     * @return string Absolute path: single leading slash, no double slashes.
     */
    public static function normalize_filename($prefix, $filepath) {
        $path = '/' . ltrim((string)$prefix . (string)$filepath, '/');
        return preg_replace('#/+#', '/', $path);
    }

    /**
     * Compute a Huawei/Method-A auth_key value for a path.
     *
     * sstring  = "<filename>-<ts>-<rand>-<uid>-<key>"
     * auth_key = "<ts>-<rand>-<uid>-HASH(sstring)"   HASH = sha256 (default) or md5
     * Pure string logic (no Moodle deps) so it is unit-testable in isolation.
     *
     * @param string $filename Signed URI path (leading slash, no query).
     * @param string $key      Shared signing key.
     * @param string $uid      uid component (usually '0').
     * @param string $rand     rand component (usually '0').
     * @param int    $ts       Unix signing timestamp.
     * @param string $algo     'sha256' (default, recommended) or 'md5' — must
     *                         match the CDN's Encryption Algorithm setting.
     * @return string The auth_key parameter value.
     */
    public static function methoda_authkey($filename, $key, $uid, $rand, $ts, $algo = 'sha256') {
        $sstring = $filename . '-' . $ts . '-' . $rand . '-' . $uid . '-' . $key;
        $hash = ($algo === 'md5') ? md5($sstring) : hash('sha256', $sstring);
        return $ts . '-' . $rand . '-' . $uid . '-' . $hash;
    }
}
