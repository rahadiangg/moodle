# CDN delivery for Moodle files (token-auth signer)

Serve Moodle's user files (PDF/Word/Excel/media) through a **CDN** in front of a
**private** object-storage bucket — cached at the edge, links that expire, and the
bucket never made public. Implemented by the optional, provider-neutral local
plugin [`local_objectfs_cdntoken`](../local/objectfs_cdntoken/README.md).

- [Why this exists](#why-this-exists)
- [Why extend ObjectFS instead of forking](#why-extend-objectfs-instead-of-forking)
- [Architecture & request flow](#architecture--request-flow)
- [What you need](#what-you-need)
- [1. Configure the CDN](#1-configure-the-cdn-huawei-shown)
- [2. Configure Moodle (the chart)](#2-configure-moodle-the-chart)
- [3. Verify](#3-verify)
- [Operations & troubleshooting](#operations--troubleshooting)
- [Extending to another CDN](#extending-to-another-cdn)
- [Limitations](#limitations)

---

## Why this exists

Moodle gates every file behind a capability check in `pluginfile.php`. The
ObjectFS plugin can offload the *bytes* to S3-compatible storage and optionally
**redirect** the browser to a time-limited **presigned URL** so files don't stream
through PHP. That works — but it **does not cache on a CDN**:

- A presigned URL is **unique per request** (signature + expiry in the query
  string). A CDN caches by URL, so every user gets a distinct cache key →
  ~100% cache miss → no edge caching, no origin-egress savings.
- You *can* tell the CDN to ignore the signature for the cache key — but then it
  serves the cached object to **anyone** with the path, with no per-request
  signature re-check → **access control is broken**.

So presigned-URLs-through-a-CDN gives you edge TLS/latency but **not** the cache
offload that is the whole point at scale. ObjectFS's only "custom delivery
domain" support is **CloudFront** (AWS-specific RSA signing); there is no Huawei /
generic-CDN equivalent.

**The fix:** sign the redirect with the **CDN's own token authentication**
(Method A: `auth_key=timestamp-rand-uid-hash`). The CDN validates the token at the
edge, and its cache key is configured to **ignore `auth_key`** — so per-user URLs
share one cached object (real offload) while expiry + tamper-protection stay
enforced at the edge, and the bucket stays private (CDN pulls via origin auth).

## Why extend ObjectFS instead of forking

We add a **separate local plugin that subclasses ObjectFS** — we do **not** fork or
patch it.

| Approach | Verdict |
|---|---|
| Fork ObjectFS | ❌ own every upstream security update forever |
| Patch the cloned source at build time | ❌ re-apply on every upgrade; fragile |
| **Separate plugin that `extends` ObjectFS classes** | ✅ chosen |

`local_objectfs_cdntoken` extends `\tool_objectfs\local\store\s3\client` and
`\tool_objectfs\s3_file_system`, overriding **one** method (`generate_presigned_url`)
to emit a CDN token URL; everything else (upload, verify, streaming, presigned
gating) is inherited from vanilla ObjectFS. Tradeoff: it depends on ObjectFS's
internal API, so the branch is **pinned** (`MOODLE_404_STABLE`) and the PHPUnit
suite includes an **API-drift guard** that fails loudly if upstream renames the
seam.

## Architecture & request flow

```
user ──GET pluginfile.php/...──▶ Moodle web pod
                                  │  capability check (enrolled? allowed?)   ← access control here
                                  │  302 Location: https://<cdn>/<key>?auth_key=ts-rand-uid-HASH
                                  ▼
user ──follows 302───────────▶ CDN edge
                                  │  validate auth_key (HASH + not expired) → 403 if bad
                                  │  cache HIT?  → serve from edge
                                  │  cache MISS? → pull from PRIVATE bucket via origin auth
                                  ▼
                               object storage (private)
```

- **Access control is preserved:** the capability check runs *before* any URL is
  issued, so an unauthorized user never receives a signed URL.
- **Caching works:** the edge cache key ignores `auth_key`, so all users of the
  same (content-hash) object share one cached copy.
- **`HASH`** = SHA256 (recommended) or MD5 — must match the CDN setting.

## What you need

- A **CDN that supports Method-A token auth**: Huawei Cloud CDN ✅, Alibaba Cloud
  CDN (Type A) ✅, likely other A-type schemes (verify param + field order). **Not**
  AWS CloudFront (use ObjectFS `signingmethod=cf`) or BunnyCDN/Cloudflare/Akamai
  (different schemes — see [Extending](#extending-to-another-cdn)).
- The **`-cdntoken` image variant** (built with `--build-arg INCLUDE_CDN_PLUGIN=true`).
  The base image has no CDN code; enabling CDN on it fails at boot.
- ObjectFS already enabled (`objectfs.enabled: true`) with a private bucket.

## 1. Configure the CDN (Huawei shown)

On your acceleration domain:

1. **Origin** = your OBS bucket (the same bucket ObjectFS writes to). Turn
   **OBS Pull Authentication ON** so the CDN can read the **private** bucket; keep
   the bucket private.
2. **HTTPS** — bind a certificate and force HTTPS (recommended).
3. **Token Authentication → Signing Method A**:
   - **Signing key** — 6–32 chars, **letters and digits only** (Huawei constraint).
     This same value goes in Moodle's `cdn-signing-key` secret.
   - **Encryption algorithm** — **SHA256** (recommended) or MD5. **Must match**
     `objectfs.cdn.algorithm` exactly, or every request 403s.
   - **Authentication parameter** = `auth_key` (matches `objectfs.cdn.authParam`).
   - **Validity period** = e.g. `1800` s. **Must equal** `objectfs.cdn.validity`.
   - **Time format** = Decimal.
4. **Cache → URL parameter filtering** = **Ignore** the `auth_key` parameter. This
   is the single most important setting — without it the cache is defeated.
5. **Cache TTL** — long (e.g. 30 days) for the object path; content-hash objects
   are immutable.
6. **Range requests** — basic byte-range works by default; enable "Video Seek"
   only for in-player video scrubbing.

> Config changes propagate to edge PoPs over ~1 minute. After changing the
> algorithm, expect a short window where the old setting still applies.

## 2. Configure Moodle (the chart)

```yaml
image:
  tag: "<version>-cdntoken"     # the variant with the plugin baked in

objectfs:
  enabled: true
  # ... bucket/region/endpoint ...
  presignedUrls:
    enabled: true
    minFileSize: 0              # 0 so documents (not just big files) redirect
    whitelist: "*"             # file types eligible (or e.g. ".pdf .docx .mp4")
  cdn:
    enabled: true
    domain: "cdn.example.com"   # acceleration domain host
    scheme: "https"
    algorithm: "sha256"         # MUST match the CDN "Encryption Algorithm"
    validity: 1800              # MUST equal the CDN validity window
    authParam: "auth_key"
    # signingMethod tokenA, uid 0 -> defaults
```

Put the signing key in the credential secret (never in values):

```bash
kubectl -n moodle create secret generic moodle-secrets \
  --from-literal=cdn-signing-key=<your-signing-key>   # plus db/redis/admin/s3 keys
# or patch an existing one:
kubectl -n moodle patch secret moodle-secrets --type merge \
  -p '{"stringData":{"cdn-signing-key":"<your-signing-key>"}}'
```

With CDN **off** (the default) the chart emits zero CDN wiring and behaves
exactly as the base chart. See [`examples/huawei-cce/`](../examples/huawei-cce/)
for a complete overlay.

## 3. Verify

**Plumbing, no Moodle (hand-signed `curl`):** sign a real object key and fetch it.

```bash
DOMAIN=cdn.example.com; URI=/<aa>/<bb>/<sha1>; KEY=<signing-key>; TS=$(date +%s)
HASH=$(printf '%s' "$URI-$TS-0-0-$KEY" | shasum -a 256 | awk '{print $1}')   # md5: openssl md5 -r
curl -sS -D- -o /dev/null "https://$DOMAIN$URI?auth_key=$TS-0-0-$HASH"
#  expect: 200, and x-hcs-proxy-type 0 (miss) then 1 (hit) on a second request.
#  expired/tampered/no-token  -> 403 ;  Range (curl -r 0-9) -> 206
```

**Deployed plugin:** confirm Moodle's active filesystem and that it generates a
CDN URL the edge serves:

```bash
kubectl exec -n moodle deploy/moodle-web -c moodle -- \
  sh -c 'cd /var/www/html && moosh -n config-get tool_objectfs filesystem'
#  expect: \local_objectfs_cdntoken\file_system
```

## Operations & troubleshooting

| Symptom | Cause / fix |
|---|---|
| Pods crash on boot after enabling CDN | Image isn't the `-cdntoken` variant. Class `\local_objectfs_cdntoken\file_system` missing. Use the variant. |
| Every request 403s | Algorithm mismatch (CDN vs `objectfs.cdn.algorithm`), wrong key, or wrong validity. After changing the CDN algorithm, allow ~1 min propagation. |
| No edge caching / high origin egress | CDN cache key still includes `auth_key`. Set URL-parameter filtering to **ignore `auth_key`**. |
| Downloads named by a hash, not the real filename | Token auth has no per-request `Content-Disposition`; set object `Content-Disposition` at upload time if needed. Accepted default. |
| "Moodle upgrade pending, cannot manage tasks" after deploying a new plugin version | Run `php admin/cli/purge_caches.php` (the migrate hook installs the plugin; a cached `allversionshash` can linger). |
| Rolling upgrade stuck (new pod `Pending`) | `hard` pod anti-affinity + `maxUnavailable:0` when **web replicas == nodes** leaves the surge pod nowhere to schedule. Keep **nodes > web replicas** in production, or use `web.podAntiAffinity: soft`. |
| ObjectFS admin "presigned URL test" page works | Yes — a compat shim (`\local_objectfs_cdntoken\file_system\client`) makes ObjectFS's admin/check pages resolve our client. File **serving** never depended on it. |
| **Rollback** | Set `objectfs.cdn.enabled: false` and `image.tag` back to the base variant, then `helm upgrade`. ObjectFS reverts to its own presigned/PHP serving. |

## Extending to another CDN

The signing math is isolated, so adding a provider needs **no fork**:

1. Add an option to the `signingmethod` select in `local/objectfs_cdntoken/settings.php`.
2. Add a static helper next to `methoda_authkey()` in `classes/client.php`.
3. Branch on it in `generate_presigned_url()`.
4. Mirror a case in `tests/client_test.php`.

## Limitations

- Requires `tool_objectfs` with an S3-compatible store; pinned to ObjectFS branch
  `MOODLE_404_STABLE` (Moodle 4.5).
- No per-request `Content-Disposition` under token auth (see table above).
- On any misconfiguration the signer throws and ObjectFS falls back to PHP
  streaming — downloads degrade, they don't hard-fail.
