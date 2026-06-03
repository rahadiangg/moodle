# k8s-moodle

Production-oriented **Moodle 4.5 LTS** on Kubernetes: a custom container image +
a lean, **cloud-agnostic** Helm chart. Built as a free alternative to the Bitnami
chart, designed for large scale (target: Huawei Cloud CCE, but portable to any cluster).

## What's here

```
.
├── Dockerfile        # erseco/alpine-moodle:v4.5.11 + Catalyst ObjectFS + local_aws
├── charts/           # the Helm chart (Chart.yaml at the root of charts/)
│   ├── values.yaml          # cloud-agnostic defaults
│   ├── values-huawei.yaml   # worked Huawei CCE example (annotations only)
│   └── README.md            # full chart docs
```

## Architecture

- **Web tier** — stateless, HPA-scaled; **cron** — exactly one replica.
- **ObjectFS → S3-compatible object storage** (AWS S3 / MinIO / Huawei OBS) for bulk files,
  with optional **presigned URLs** so clients fetch large files directly from storage.
- **Shared `moodledata`** on an RWX volume (still required by Moodle even with ObjectFS).
- **External PostgreSQL** (optionally via a transaction-mode pooler) + **single Redis**
  (sessions + MUC cache, auto-configured by the image).
- **Install/upgrade** via ordered Helm hook Jobs (migrate → configure) with a
  `wait-for-schema` init gate so pods never serve a stale schema.

No cloud-specific template logic — load balancer, storage class, registry, and
object-store endpoint are all driven by plain values + annotations.

## Quickstart

```bash
# 1) Build & push the image (from this directory)
docker buildx build --platform linux/amd64 --provenance=false --sbom=false \
  -t <registry>/moodle-objectfs:4.5.11-2 --push .

# 2) Create the credential Secret
kubectl -n moodle create secret generic moodle-secrets \
  --from-literal=db-password=... --from-literal=redis-password=... \
  --from-literal=admin-password=... \
  --from-literal=s3-access-key=... --from-literal=s3-secret-key=...

# 3) Deploy (edit values first; values-huawei.yaml is a CCE example)
helm upgrade --install moodle charts -n moodle --create-namespace \
  --set auth.existingSecret=moodle-secrets -f charts/values-huawei.yaml
```

See [`charts/README.md`](charts/README.md) for the full configuration reference,
the connection-storm guardrail, and a local MinIO-based end-to-end test.

## Notes

- **Moodle 4.5 LTS, not 5.x** — Catalyst ObjectFS officially supports up to branch 405;
  on Moodle 5 its S3 client has open signing/auth bugs.
- **Huawei SWR** rejects buildx attestation manifests — keep `--provenance=false --sbom=false`.
- On **CCE Turbo (Yangtse/Cilium)**, allow the container CIDR (e.g. `172.16.0.0/12`) in your
  RDS/DCS security groups — pods are not SNAT'd to the node.
