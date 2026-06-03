# Moodle (lean) Helm chart

Moodle 4.5 LTS for Kubernetes — **cloud-agnostic** (tested on Huawei Cloud CCE).
Stateless web tier (HPA) + single cron, ObjectFS to **S3-compatible object storage**,
shared `dataroot` on an **RWX** volume, sessions + MUC cache on one **Redis**, external
**PostgreSQL** (optionally via a transaction-mode pooler like PgBouncer).

Built around the custom image (`../Dockerfile`): `erseco/alpine-moodle:v4.5.11`
+ Catalyst ObjectFS (`MOODLE_404_STABLE`) + local_aws, with an env-templatized php-fpm pool.

There is **no cloud-specific template logic** — every provider integration (load balancer,
storage class, registry, object-store endpoint) is driven by plain values + annotations.
See `values-huawei.yaml` for a worked Huawei CCE example.

## Why these choices
- **4.5 LTS, not 5.x** — ObjectFS officially supports branch 405 only; on Moodle 5 its S3
  client has open signing/auth bugs.
- **External PostgreSQL / Redis / object storage** — managed by you; the chart points at them.
- **Single Redis** — sessions + MUC; the image auto-configures both when `externalRedis.host`
  is set. Set the Redis `maxmemory-policy = noeviction` so sessions survive.

## Prerequisites
- Kubernetes ≥ 1.28 with an **RWX-capable StorageClass** (NFS/CephFS/EFS/Filestore/SFS Turbo…).
- PostgreSQL (managed or in-cluster); optionally a transaction-mode pooler.
- Redis (managed or in-cluster).
- An S3-compatible bucket (AWS S3, MinIO, Huawei OBS, …) if `objectfs.enabled`.
- The image built & pushed to your registry (run from the project root):
  `docker buildx build --platform linux/amd64 -t <repo>:4.5.11-2 --push .`
  (Huawei SWR rejects buildx attestation manifests — add `--provenance=false --sbom=false`.)

## Install
```bash
# 1) Catch-all credential Secret (keys the chart reads via existingSecret)
kubectl -n moodle create secret generic moodle-secrets \
  --from-literal=db-password=...  --from-literal=db-password-replica=... \
  --from-literal=redis-password=... --from-literal=admin-password=... \
  --from-literal=s3-access-key=... --from-literal=s3-secret-key=...

# 2) Edit values (endpoints, bucket, registry) or use an overlay, then:
helm upgrade --install moodle . -n moodle --create-namespace \
  --set auth.existingSecret=moodle-secrets -f values-huawei.yaml   # or your own values

# 3) Watch + smoke test
kubectl -n moodle rollout status deploy/moodle-web
helm test moodle -n moodle
```

Install/upgrade ordering (Helm hooks): supporting ConfigMaps/Secret (`-5`) → **db-migrate**
Job (`0`, direct to the DB) → **configure** Job (`5`, ObjectFS settings) → web/cron pods, which
block in a `wait-for-schema` init container until the schema exists. A failed migrate aborts
the upgrade with the old pods untouched.

## Exposing the service
Two standard, cloud-agnostic options:
- **Service `type: LoadBalancer` + `service.annotations`** — let your cloud provision an external
  LB (AWS/GCP/Azure/Huawei ELB via the provider's annotations).
- **`ingress.enabled` + `ingress.className`/`annotations`/`hosts`/`tls`** — behind any ingress
  controller. Put TLS/body-size/health-check/LB annotations in `ingress.annotations`.

(Huawei example: `ingress.className: cce` with `kubernetes.io/elb.*` annotations — see
`values-huawei.yaml`.)

## Connection-storm guardrail
Peak client connections into the DB/pooler = `autoscaling.maxReplicas × php.maxChildren`.
A transaction-mode pooler collapses these to a small server pool. Ensure:

```
pooler default_pool_size × pooler_instances  <  DB max_connections − ~50 reserve
```

Reads route to the replica via `externalDatabase.readReplica`. The migrate/configure Jobs go
**direct to the DB** (`install_db.host`) — schema migration breaks under transaction pooling.

## Local end-to-end test
Throwaway Postgres + Redis + MinIO (object-store stand-in), point the chart at them:
```bash
helm install moodle . \
  --set image.repository=<local-or-registry> \
  --set externalDatabase.host=postgres --set externalDatabase.port=5432 \
  --set install_db.host=postgres --set install_db.port=5432 \
  --set externalDatabase.dbHandleOptions=false \
  --set externalRedis.host=redis \
  --set objectfs.endpoint=http://minio:9000 --set objectfs.bucket=moodle \
  --set persistence.storageClass=standard --set persistence.accessMode=ReadWriteOnce \
  --set ingress.enabled=false \
  --set auth.dbPassword=... --set auth.adminPassword=... \
  --set auth.s3AccessKey=... --set auth.s3SecretKey=...
```
Verify: db-migrate completes → configure sets ObjectFS → web readiness passes → upload a file
in Moodle → object appears in the bucket (proves the ObjectFS path).

## Key values
See `values.yaml` (documented inline). Most-tuned: `php.maxChildren`, `php.pm`,
`autoscaling.{min,max}Replicas`, `externalDatabase.*`, `install_db.*`, `objectfs.*`,
`persistence.*`, `service.*` (type/annotations), `ingress.*` (className/annotations/hosts/tls).

Phase-2 toggles (default off): `metrics.fpmExporter`, `networkPolicy`, `topologySpread`.
