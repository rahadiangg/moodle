<?php
// Unit tests for the CDN token-auth signer.

namespace local_objectfs_cdntoken;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_objectfs_cdntoken\client
 * @covers \local_objectfs_cdntoken\file_system
 */
final class client_test extends \advanced_testcase {

    /** A valid 40-char sha1 content hash for path building. */
    const HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Build a client WITHOUT running the (AWS-SDK-dependent) parent constructor,
     * then set the inherited protected bucketkeyprefix. Keeps tests network-free.
     *
     * @param string $prefix bucket key prefix to simulate.
     * @return client
     */
    private function make_client(string $prefix = ''): client {
        $client = (new \ReflectionClass(client::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(\tool_objectfs\local\store\s3\client::class, 'bucketkeyprefix');
        $prop->setAccessible(true);
        $prop->setValue($client, $prefix);
        return $client;
    }

    /**
     * Apply CDN settings (component local_objectfs_cdntoken).
     *
     * @param array $overrides
     */
    private function set_cdn_config(array $overrides = []): void {
        $cfg = $overrides + [
            'cdndomain'  => 'cdn.example.com',
            'signingkey' => 'topsecret',
            'cdnscheme'  => 'https',
            'authparam'  => 'auth_key',
            'validity'   => 1800,
            'uid'        => '0',
        ];
        foreach ($cfg as $k => $v) {
            set_config($k, $v, 'local_objectfs_cdntoken');
        }
    }

    // ---- Pure static helpers (no Moodle/AWS needed) -------------------------

    public function test_methoda_authkey_format_and_md5(): void {
        $filename = '/da/39/' . self::HASH;
        $ts = 1700000000;
        $authkey = client::methoda_authkey($filename, 'topsecret', '0', '0', $ts);

        $parts = explode('-', $authkey);
        $this->assertCount(4, $parts);
        $this->assertSame((string)$ts, $parts[0]);
        $this->assertSame('0', $parts[1]);          // rand
        $this->assertSame('0', $parts[2]);          // uid
        $expected = md5($filename . '-' . $ts . '-0-0-topsecret');
        $this->assertSame($expected, $parts[3]);
    }

    public function test_normalize_filename_empty_prefix(): void {
        $this->assertSame(
            '/da/39/' . self::HASH,
            client::normalize_filename('', 'da/39/' . self::HASH));
    }

    /**
     * key_prefix is concatenated directly onto the filepath (ObjectFS convention:
     * the prefix carries its own trailing slash). We must mirror that exactly so
     * the signed path equals the real OBS object key. We only add a single leading
     * slash and collapse accidental double slashes.
     *
     * @dataProvider prefix_provider
     */
    public function test_normalize_filename_slash_hygiene(string $prefix): void {
        $out = client::normalize_filename($prefix, 'da/39/' . self::HASH);
        $this->assertSame('/moodle/da/39/' . self::HASH, $out);
        $this->assertStringNotContainsString('//', $out);
    }

    public static function prefix_provider(): array {
        // Valid ObjectFS prefixes carry a trailing slash; a leading slash is tolerated.
        return [['moodle/'], ['/moodle/']];
    }

    // ---- Real method (constructor bypassed; no network) --------------------

    public function test_generate_url_happy_path(): void {
        $this->set_cdn_config();
        $client = $this->make_client('');

        $before = time();
        $signed = $client->generate_presigned_url(self::HASH);
        $after = time();

        $this->assertInstanceOf(\tool_objectfs\local\store\signed_url::class, $signed);
        $this->assertInstanceOf(\moodle_url::class, $signed->url);

        $url = $signed->url->out(false);
        $filename = '/da/39/' . self::HASH;
        $this->assertStringStartsWith('https://cdn.example.com' . $filename . '?', $url);

        $authkey = $signed->url->get_param('auth_key');
        [$ts, $rand, $uid, $hash] = explode('-', $authkey);
        $this->assertSame('0', $rand);
        $this->assertSame('0', $uid);
        $this->assertGreaterThanOrEqual($before, (int)$ts);
        $this->assertLessThanOrEqual($after, (int)$ts);
        $this->assertSame(md5($filename . '-' . $ts . '-0-0-topsecret'), $hash);

        // expiresat = signing ts + validity.
        $this->assertSame((int)$ts + 1800, (int)$signed->expiresat);
    }

    public function test_prefix_appears_in_path(): void {
        $this->set_cdn_config();
        $client = $this->make_client('moodle/');
        $url = $client->generate_presigned_url(self::HASH)->url->out(false);
        $this->assertStringStartsWith('https://cdn.example.com/moodle/da/39/' . self::HASH . '?', $url);
        // No double slash between host and path.
        $this->assertStringNotContainsString('cdn.example.com//', $url);
    }

    public function test_custom_authparam_and_scheme(): void {
        $this->set_cdn_config(['authparam' => 'sign', 'cdnscheme' => 'http']);
        $client = $this->make_client('');
        $signed = $client->generate_presigned_url(self::HASH);
        $url = $signed->url->out(false);
        $this->assertStringStartsWith('http://cdn.example.com/', $url);
        $this->assertNotNull($signed->url->get_param('sign'));
        $this->assertNull($signed->url->get_param('auth_key'));
    }

    public function test_domain_trailing_slash_trimmed(): void {
        $this->set_cdn_config(['cdndomain' => 'cdn.example.com/']);
        $client = $this->make_client('');
        $url = $client->generate_presigned_url(self::HASH)->url->out(false);
        $this->assertStringNotContainsString('cdn.example.com//', $url);
        $this->assertStringStartsWith('https://cdn.example.com/da/39/', $url);
    }

    public function test_validity_floor(): void {
        $this->set_cdn_config(['validity' => 10]);   // below 60 -> defaults to 1800
        $client = $this->make_client('');
        $signed = $client->generate_presigned_url(self::HASH);
        $ts = (int)explode('-', $signed->url->get_param('auth_key'))[0];
        $this->assertSame($ts + 1800, (int)$signed->expiresat);
    }

    public function test_uid_respected(): void {
        $this->set_cdn_config(['uid' => '42']);
        $client = $this->make_client('');
        $authkey = $client->generate_presigned_url(self::HASH)->url->get_param('auth_key');
        [$ts, $rand, $uid, $hash] = explode('-', $authkey);
        $this->assertSame('42', $uid);
        $this->assertSame(md5('/da/39/' . self::HASH . '-' . $ts . '-0-42-topsecret'), $hash);
    }

    public function test_throws_when_domain_empty(): void {
        $this->set_cdn_config(['cdndomain' => '']);
        $client = $this->make_client('');
        $this->expectException(\coding_exception::class);
        $client->generate_presigned_url(self::HASH);
    }

    public function test_throws_when_signingkey_empty(): void {
        $this->set_cdn_config(['signingkey' => '']);
        $client = $this->make_client('');
        $this->expectException(\coding_exception::class);
        $client->generate_presigned_url(self::HASH);
    }

    // ---- API-drift guard: our subclassing assumptions still hold -----------

    public function test_class_wiring_against_objectfs(): void {
        // We extend the ObjectFS classes (not fork them).
        $this->assertContains(
            'tool_objectfs\\local\\store\\s3\\client',
            class_parents(client::class));
        $this->assertContains(
            'tool_objectfs\\s3_file_system',
            class_parents(file_system::class));

        // The seams we override must have the expected visibility upstream.
        $this->assertTrue((new \ReflectionMethod(
            \tool_objectfs\local\store\s3\client::class, 'generate_presigned_url'))->isPublic());
        $this->assertTrue((new \ReflectionMethod(
            \tool_objectfs\local\store\object_file_system::class, 'initialise_external_client'))->isProtected());

        // And we actually override them (declared on our classes).
        $this->assertSame(client::class, (new \ReflectionMethod(
            client::class, 'generate_presigned_url'))->getDeclaringClass()->getName());
        $this->assertSame(file_system::class, (new \ReflectionMethod(
            file_system::class, 'initialise_external_client'))->getDeclaringClass()->getName());
    }
}
