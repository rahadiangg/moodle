# Moodle Helm chart

Deploys Moodle 4.5 LTS on Kubernetes. **Cloud-agnostic** — works on any cluster; provider
specifics (load balancer, storage class, registry, object-store endpoint) are set with plain
values and annotations, never special template logic.

It runs a scalable **web** Deployment, a single **cron** Deployment, and connects to a
**PostgreSQL** database, a **Redis** cache, an optional **S3-compatible** object store, and a
shared **ReadWriteMany** volume. Files are offloaded to object storage by the bundled
[Catalyst ObjectFS](https://github.com/catalyst/moodle-tool_objectfs) plugin.

Image: `../Dockerfile` (`erseco/alpine-moodle:v4.5.11` + ObjectFS + local_aws).

## Before you start

You need:
- Kubernetes 1.28+ with a **ReadWriteMany** StorageClass (NFS, CephFS, EFS, Filestore, SFS Turbo…).
- A **PostgreSQL 13+** database and a **Redis 6+** instance (managed or in-cluster).
- An **S3-compatible bucket** if you enable object storage (`objectfs.enabled`).
- The **image built and pushed** to your registry (run from the repo root):
  ```bash
  docker buildx build --platform linux/amd64 -t <repo>:4.5.11-2 --push .
  # Huawei SWR: add  --provenance=false --sbom=false
  ```

## Install

```bash
# 1) One Secret holding all credentials
kubectl -n moodle create secret generic moodle-secrets \
  --from-literal=db-password=...  --from-literal=redis-password=... \
  --from-literal=admin-password=... \
  --from-literal=s3-access-key=... --from-literal=s3-secret-key=...

# 2) Install — copy an example from ../examples/ (or values.yaml) and edit hosts/bucket.
#    Run from the repo root so the paths resolve:
helm upgrade --install moodle charts -n moodle --create-namespace \
  --set auth.existingSecret=moodle-secrets -f examples/values-aws.yaml

# 3) Watch it come up, then smoke-test
kubectl -n moodle rollout status deploy/moodle-web
helm test moodle -n moodle
```

## What happens during install

The chart does the database setup for you, in order, before the app starts:

1. **db-migrate** — creates or upgrades the Moodle database schema (connects straight to the DB).
2. **configure** — writes the object-storage settings into Moodle.
3. **web + cron pods start** — but each waits in an init container until the schema is ready,
   so a pod never serves a half-migrated database.

If the migration fails, the upgrade stops and your old pods keep running untouched.

## Exposing the site

Pick whichever your platform uses — both are just values:

- **LoadBalancer Service** — set `service.type: LoadBalancer` and put your cloud's LB
  annotations in `service.annotations`.
- **Ingress** — set `ingress.enabled: true` with `ingress.className`, `ingress.hosts`,
  `ingress.tls`, and any controller/cloud annotations in `ingress.annotations`.

See [`../examples/`](../examples/) for full overlays — e.g. Huawei CCE uses
`ingress.className: cce` with `kubernetes.io/elb.*` annotations.

## Key settings

Everything is documented inline in [`values.yaml`](values.yaml). The ones you'll touch most:

| Setting | What it does |
|---|---|
| `image.repository` / `image.tag` | your built image |
| `externalDatabase.*`, `install_db.*` | database connection |
| `externalRedis.host` | Redis connection (enables sessions + cache) |
| `objectfs.*` | bucket, region, endpoint, presigned URLs |
| `persistence.storageClass`, `accessMode` | the shared RWX volume |
| `service.*`, `ingress.*` | how the site is exposed |
| `php.maxChildren`, `autoscaling.*` | scaling |

Object storage is optional: set `objectfs.enabled=false` to keep all files on the shared volume.

## Try it locally

Spin up throwaway Postgres + Redis + MinIO (a local S3) and point the chart at them:

```bash
helm install moodle . \
  --set image.repository=<your-image> \
  --set externalDatabase.host=postgres --set install_db.host=postgres \
  --set externalRedis.host=redis \
  --set objectfs.endpoint=http://minio:9000 --set objectfs.bucket=moodle \
  --set persistence.storageClass=standard --set persistence.accessMode=ReadWriteOnce \
  --set ingress.enabled=false \
  --set auth.dbPassword=... --set auth.adminPassword=... \
  --set auth.s3AccessKey=... --set auth.s3SecretKey=...
```

Then upload a file in Moodle — it should appear in the MinIO bucket.

## Tuning for large deployments (optional)

Skip this unless you're running at scale. Each web pod opens up to `php.maxChildren`
database connections, so peak connections ≈ `autoscaling.maxReplicas × php.maxChildren`.
If that exceeds your database's `max_connections`, put a transaction-mode pooler (e.g.
PgBouncer) in front of PostgreSQL and point `externalDatabase.host` at it. Keep:

```
pooler pool_size × pooler_instances  <  DB max_connections − ~50 reserve
```

Reads can be sent to a replica via `externalDatabase.readReplica`. The setup Jobs always
connect **directly** to the DB (`install_db.host`), because schema migrations don't work
through a transaction-mode pooler.

Off-by-default extras: `metrics.fpmExporter`, `networkPolicy`, `topologySpread`.
