<?php
// English strings for local_objectfs_cdntoken.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ObjectFS CDN token-auth signed URLs';

$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Sign file-download redirects as CDN token-auth URLs. The active filesystem must be set to \\local_objectfs_cdntoken\\file_system for this to take effect.';

$string['cdndomain'] = 'CDN domain';
$string['cdndomain_desc'] = 'Acceleration (CDN) domain host only, no scheme or trailing slash, e.g. cdn.example.com. The CDN origin must be the same object-storage bucket ObjectFS writes to.';

$string['cdnscheme'] = 'CDN scheme';
$string['cdnscheme_desc'] = 'URL scheme for the signed CDN links. Use https in production.';

$string['signingmethod'] = 'Signing method';
$string['signingmethod_desc'] = 'CDN token-authentication scheme used to sign URLs.';
$string['signingmethod:tokenA'] = 'Method A (auth_key=timestamp-rand-uid-md5)';

$string['signingkey'] = 'Signing key';
$string['signingkey_desc'] = 'Shared secret configured on the CDN token-authentication settings. Used to compute the MD5 auth_key. Keep secret.';

$string['authparam'] = 'Auth parameter name';
$string['authparam_desc'] = 'Query-parameter name the CDN expects for the token (default auth_key).';

$string['validity'] = 'Validity (seconds)';
$string['validity_desc'] = 'Lifetime of a signed link. MUST equal the validity window configured on the CDN, which enforces expiry. Minimum recommended 300s to absorb clock skew.';

$string['uid'] = 'UID field';
$string['uid_desc'] = 'The uid component of the Method-A auth_key. Usually 0.';

$string['privacy:metadata'] = 'The ObjectFS CDN token-auth plugin does not store any personal data.';
