# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A cloud-agnostic way to run **Moodle 4.5 LTS** on Kubernetes. Two deliverables:

1. A **container image** (`Dockerfile`) — `erseco/alpine-moodle` + the Catalyst ObjectFS plugin (offloads files to S3-compatible storage) + `local_aws` (AWS SDK) + an optional in-tree CDN signer plugin.
2. A **Helm chart** (`charts/`) that deploys it.

The chart never contains provider-specific template logic. Cloud specifics (load balancer, storage class, registry, object-store endpoint) go through plain values + annotations; ready overlays live in `examples/<cloud>/`. Keep it that way.

Pinned to Moodle 4.5 because Catalyst ObjectFS declares `supported = [404, 405]` and has no 5.x branch.

## Commands

```bash
# Lint + render the chart (run from repo root)
helm lint charts -f <(echo 'auth: {dbPassword: x, adminPassword: x, s3AccessKey: x, s3SecretKey: x}')
helm template moodle charts -f examples/aws-eks/values.yaml

# Build the image (amd64; cross-build from arm64 Macs).
# Huawei SWR rejects buildx attestation manifests, so disable them:
docker buildx build --platform linux/amd64 --provenance=false --sbom=false \
  -t <registry>/moodle-objectfs:4.5.11-5 --push .

# Build the CDN-plugin variant ("-cdntoken" image)
docker buildx build --build-arg INCLUDE_CDN_PLUGIN=true ... .

# Run the CDN signer plugin's PHPUnit suite (builds a throwaway --target test
# image + ephemeral Postgres; production image untouched). Run from repo root.
bash scripts/run-plugin-tests.sh
# Pass extra phpunit args through, e.g. a single test:
bash scripts/run-plugin-tests.sh --filter test_methoda_authkey
```

There is no chart-side test framework beyond `helm lint`/`helm template` and `helm test moodle` (the in-cluster smoke test under `charts/templates/tests/`). PHPUnit only covers the in-tree CDN plugin.

## Architecture

### Runtime topology (what the chart deploys)
- **web** Deployment — serves the site, scales via HPA. Each pod opens up to `php.maxChildren` DB connections, so peak ≈ `autoscaling.maxReplicas × php.maxChildren`. At scale, front PostgreSQL with a transaction-mode pooler (PgBouncer) and point `externalDatabase.host` at it.
- **cron** Deployment — always exactly one replica; runs Moodle scheduled tasks.
- Backing services are **external and user-provided**: PostgreSQL 13+, Redis 6+ (sessions + MUC cache), an S3-compatible bucket (optional, `objectfs.enabled`), and a **required `ReadWriteMany` volume** for `moodledata` (needed even when ObjectFS is on).

### The split that matters: baked code vs. runtime settings
Plugin **code** is baked into the image at build time (reproducible, survives autoscale, no GitHub dependency at pod start). Plugin **settings** (bucket, keys, endpoint, CDN config) are written into the Moodle DB at deploy time by Helm hook Jobs running `moosh`. When changing ObjectFS/CDN behavior, decide which half you're touching — `Dockerfile` for code, `charts/templates/configmap-scripts.yaml` + `configmap-env.yaml` for settings.

### Install ordering (Helm pre-install/pre-upgrade hooks)
Hook weights enforce sequence so a pod never serves a half-migrated DB:
1. weight `-5`: `secret`, `configmap-env`, `configmap-scripts` (so the Jobs can reference them).
2. weight `0`: **`job-db-migrate`** — creates/upgrades the schema, connecting **directly** to the DB (`install_db.host`), never through a pooler (schema migration breaks under transaction-mode pooling).
3. weight `5`: **`job-configure`** — `moosh` writes ObjectFS/CDN settings into `mdl_config_plugins` (idempotent upsert).
4. web/cron pods start, each gated by a `wait-for-schema.sh` init container that blocks until the `config` table exists.

If migration fails the upgrade stops and old pods keep running.

### Runtime scripts (`charts/templates/configmap-scripts.yaml`)
Mounted at `/scripts`, **static** (no Helm templating inside the shell bodies — driven entirely by env vars, identical everywhere), busybox-safe:
- `post-configure.sh` — runs on **every** pod via `POST_CONFIGURE_COMMANDS` after `config.php` is generated. Idempotently inserts `$CFG` overrides (node-local cache/temp dirs, and `alternative_file_system_class` when ObjectFS/CDN is on) above the `lib/setup.php` require. Marker-guarded; refuses to start if the anchor is missing.
- `objectfs-configure.sh` — runs **once** in the configure Job; `moosh config-set` for all ObjectFS + CDN settings. Switches the active `filesystem` class **last**, after all settings exist.
- `wait-for-schema.sh` — the init-container gate; uses the `pgsql` extension (image ships `pgsql`, not `pdo_pgsql`) and no Moodle bootstrap (config.php doesn't exist yet).

### CDN signer plugin (`local/objectfs_cdntoken/`)
Provider-neutral Moodle local plugin for **Method-A token-auth** signed CDN URLs over a private bucket. It does **not** fork ObjectFS — `classes/file_system.php` subclasses `\tool_objectfs\s3_file_system` and swaps in `classes/client.php`, which overrides `generate_presigned_url()` to emit a CDN signed URL instead of an S3 presigned one. On any misconfig the signer throws and ObjectFS falls back to streaming through PHP (downloads degrade, don't break).

Hard-pinned to ObjectFS branch `MOODLE_404_STABLE` because it depends on `s3\client::generate_presigned_url()` and `object_file_system::initialise_external_client()`. The PHPUnit suite has an **API-drift guard** that fails if a future ObjectFS release changes those signatures — if that test breaks after a branch bump, the subclass contract changed, not the test.

Works with Method-A CDNs (Huawei, Alibaba, likely Tencent). NOT CloudFront — that uses RSA key-pair signing; use ObjectFS's native `signingmethod=cf` instead. To add another A-type CDN, follow the 4-step recipe in `local/objectfs_cdntoken/README.md` (add `signingmethod` option, add a helper next to `methoda_authkey()`, branch in `generate_presigned_url()`, add a test).

## Dockerfile specifics worth knowing
- The image **templatizes the PHP-FPM pool** (`pm`, `pm.max_children`, `pm.max_requests`) into `${...}` placeholders that the erseco entrypoint fills via `envsubst` at boot — this is the per-pod connection-storm lever, set from the chart's `php.*` values. ENV defaults (`ondemand`/`100`/`2000`) preserve stock behavior.
- It **patches the upstream install script** to quote human-input args (`--fullname`, `--adminpass`, etc.) so a site name with spaces doesn't break first install. Both patches have `grep` guards that fail the build if upstream renames the target — don't remove the guards.
- A separate `--target test` stage adds composer + Postgres client and force-includes the CDN plugin for PHPUnit. Never push the test stage.

## Adding a Moodle plugin

**Don't use the dashboard's "upload ZIP" page** — it writes code to one ephemeral pod, lost on restart and absent from other replicas. Bake the code into the image instead.

1. Add the code under its plugin-type dir (`mod/`, `local/`, `blocks/`, `admin/tool/`, …) in the `Dockerfile`: `git clone` a third-party plugin (like ObjectFS), or `COPY` your own (like `objectfs_cdntoken`). Pin to a Moodle 4.5-compatible branch.
2. Bump the image tag and `helm upgrade --set image.tag=<new>`. This re-fires `job-db-migrate`, which runs `upgrade.php` to register the plugin and create its tables, then rolls the pods.

Rolling pods alone isn't enough — only `helm upgrade` runs the DB migration. It's safe for the running site: installs are additive, and if the migrate Job fails the upgrade stops with old pods still serving.

The dashboard installer is disabled by default (`moodle.disablePluginInstaller`, sets `$CFG->disableupdateautodeploy`) precisely because baking is the only supported path — don't re-enable it in production.

## Conventions
- **Never commit secrets or real infra** — endpoints, keys, passwords, bucket names, account IDs, kubeconfigs. Use `REPLACE_*` placeholders and `existingSecret`.
- Every chart value is documented inline in `charts/values.yaml`; update those comments when adding/renaming a value.
- Adding a cloud: create `examples/<cloud>/` with `values.yaml` (generic values + that cloud's annotations), the required RWX `StorageClass` manifest, and a prerequisites `README.md`. No cloud-specific Go templating in the chart.

## Docs map
- `README.md` — top-level overview + quickstart.
- `charts/README.md` — full chart config reference + local end-to-end test (MinIO as S3 stand-in).
- `docs/cdn.md` — authoritative CDN guide (why, architecture, console setup, ops).
- `local/objectfs_cdntoken/README.md` — plugin-level reference.
- `CONTRIBUTING.md` — dev setup, test commands, conventions.
