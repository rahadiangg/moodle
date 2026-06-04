# Tests — local_objectfs_cdntoken

`client_test.php` validates the CDN Method-A signer:
- pure signing math (`methoda_authkey`, `normalize_filename`) + edge cases;
- the real `generate_presigned_url()` path (constructor bypassed via reflection,
  so no AWS SDK / network needed) — URL shape, expiry, custom params, throws-on-misconfig;
- an **API-drift guard** (`test_class_wiring_against_objectfs`) that fails loudly if
  a future ObjectFS upgrade renames/privatizes the methods we subclass.

## Running

The production image ships no PHPUnit, so tests run via a throwaway **test image**
(adds composer + dev deps) plus an ephemeral Postgres. From the repo root:

```bash
bash scripts/run-plugin-tests.sh
```

That builds `--target test`, starts Postgres, runs Moodle's
`admin/tool/phpunit/cli/init.php`, then `vendor/bin/phpunit -c local/objectfs_cdntoken`,
and tears everything down. Exit code is non-zero on any failure.

> The test database (`$CFG->phpunit_*`) is separate and ephemeral — it never
> touches a production database.
