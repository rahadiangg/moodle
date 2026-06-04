# local_objectfs_cdntoken — CDN token-auth signed URLs for ObjectFS

A small, **provider-neutral** Moodle local plugin that makes [Catalyst
ObjectFS](https://github.com/catalyst/moodle-tool_objectfs) deliver files through
a **CDN** that signs URLs with a token the edge validates ("Method A" token
authentication), while the object-storage bucket stays **private**.

It does **not** fork or modify ObjectFS. It subclasses two ObjectFS classes and
overrides one method; everything else (upload, verify, streaming, presigned
gating) is inherited unchanged. ObjectFS is installed vanilla alongside it.

## How it works

1. A user requests a file → Moodle `pluginfile.php` runs the normal **capability
   check** (so access control is preserved — an unauthorized user never gets a URL).
2. ObjectFS would redirect to an S3 presigned URL; this plugin instead returns a
   **CDN Method-A signed URL** and Moodle 302-redirects to it.
3. The CDN validates the token at the edge (expired/tampered → 403), serves from
   its edge cache, or pulls from the private bucket via the CDN's origin auth.

**Method A format:**

```
auth_key = "<ts>-<rand>-<uid>-" + md5("<uri>-<ts>-<rand>-<uid>-<key>")
URL      = "<scheme>://<cdn-domain><uri>?<authParam>=<auth_key>"
```
where `<uri>` is the object key path (`/<key_prefix><aa>/<bb>/<sha1>`) and `<key>`
is the shared signing key configured on both Moodle and the CDN.

## Which CDNs does it work with?

| CDN | Supported | Notes |
|---|---|---|
| **Huawei Cloud CDN** | ✅ | Signing Method A. Built and tested against this. |
| **Alibaba Cloud CDN** | ✅ | "URL authentication Type A" — identical algorithm. |
| **Tencent Cloud CDN** & other A-type schemes | ⚠️ likely | Verify the **param name** (set via `authParam`) and that the hash field order is `uri-ts-rand-uid-key`. If a provider orders fields differently, add a method (below). |
| **AWS CloudFront** | ❌ use ObjectFS `cf` | CloudFront uses RSA key-pair signing — ObjectFS supports it natively via `signingmethod=cf`. Don't use this plugin for CloudFront. |
| **BunnyCDN / Cloudflare / Akamai / Fastly** | ❌ not yet | Each has its own token scheme (different inputs / HMAC). Add a method to support. |

So: **provider-neutral within the Method-A token-auth family**, not Huawei-specific.

## Configuration

Settings live under **Site administration → Plugins → Local plugins → ObjectFS
CDN token-auth signed URLs** (component `local_objectfs_cdntoken`):

| Setting | Meaning |
|---|---|
| `cdndomain` | CDN/acceleration domain host, e.g. `cdn.example.com` |
| `cdnscheme` | `https` (default) or `http` |
| `signingmethod` | `tokenA` (only option today) |
| `signingkey` | shared secret, identical to the CDN's token-auth key |
| `authparam` | query-param name the CDN expects (default `auth_key`) |
| `validity` | seconds; **must equal** the CDN-configured validity window |
| `uid` | Method-A uid field (usually `0`) |

To activate, set Moodle's `alternative_file_system_class` (and ObjectFS's
`filesystem`) to `\local_objectfs_cdntoken\file_system`, and enable presigned
redirects (`enablepresignedurls=1`). In this repo's Helm chart that's all driven
by the `objectfs.cdn.*` values — see `charts/values.yaml` and
`examples/huawei-cce/`.

## CDN-side requirements (any provider)

- Origin = the same object-storage bucket; the CDN must be allowed to pull from
  the **private** bucket (e.g. Huawei "OBS Pull Authentication", or the
  equivalent origin auth).
- Token authentication (Method A) enabled with the **same key + validity** as the
  plugin, and the same `authParam`.
- Cache key configured to **ignore the auth param** so per-user signed URLs share
  one cache entry (otherwise caching/egress-offload is defeated).
- Long cache TTL for the content-hash path prefix (objects are immutable).

## Requirements & limitations

- Requires `tool_objectfs` (hard dependency) with an S3-compatible store.
- Pinned to ObjectFS branch `MOODLE_404_STABLE` (Moodle 4.5) — the subclass relies
  on `s3\client::generate_presigned_url()` (public) and
  `object_file_system::initialise_external_client()` (protected). The PHPUnit
  suite includes an **API-drift guard** that fails if a future ObjectFS release
  changes these.
- Under token auth there is no per-request `Content-Disposition` override, so
  downloads are named by their content hash unless the stored object carries a
  `Content-Disposition` (set at upload time on the bucket).
- On any misconfiguration the signer throws; ObjectFS catches it and falls back
  to streaming the file through PHP — downloads degrade, they don't break.

## Extending to another CDN scheme

The signing math is isolated, so adding a provider is small and needs **no fork**:
1. Add an option to the `signingmethod` select in `settings.php`
   (e.g. `'bunnytoken' => 'BunnyCDN token'`).
2. Add a static helper next to `methoda_authkey()` in `classes/client.php`.
3. Branch on the method in `generate_presigned_url()`.
4. Add an edge-case test mirroring `tests/client_test.php`.

## Tests

See [`tests/README.md`](tests/README.md). Run: `bash scripts/run-plugin-tests.sh`
(from the repo root).
