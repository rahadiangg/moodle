# Moodle

Run **Moodle** on Kubernetes. This repo gives you two things:

- a **container image** — Moodle 4.5 LTS plus the ObjectFS plugin (stores files in S3-compatible object storage), and
- a **Helm chart** to deploy it.

It runs on **any Kubernetes cluster** — a managed cloud or your own. It's a free, lean
alternative to the Bitnami Moodle chart.

## How it works

Moodle runs as two kinds of pods:

- **web** — serves the site; scales up and down with traffic.
- **cron** — runs Moodle's scheduled tasks; always exactly one.

Both connect to services **you provide** (database, cache, file storage). Uploaded files
can be offloaded to object storage so they don't fill up your disks.

```
        users
          │
     load balancer
          │
   ┌──────┴───────┐
   │  web pods    │──── PostgreSQL (database)
   │ (scalable)   │──── Redis (cache + sessions)
   └──────────────┘──── object storage (S3/MinIO/OBS, for files)
   ┌──────────────┐──┘  shared filesystem (RWX, for Moodle's data dir)
   │  cron pod    │
   └──────────────┘
```

## What you need

The chart deploys Moodle; you bring the backing services. Anything that speaks the
standard interface works — managed or self-hosted.

| You need | Interface | Examples |
|---|---|---|
| **Database** | PostgreSQL 13+ | AWS RDS, Cloud SQL, Huawei RDS, or in-cluster Postgres |
| **Cache + sessions** | Redis 6+ | ElastiCache, MemoryStore, Huawei DCS, or in-cluster Redis |
| **Shared filesystem** | a `ReadWriteMany` volume | NFS, CephFS, AWS EFS, GCP Filestore, Huawei SFS / SFS Turbo |
| **Object storage** *(optional)* | S3-compatible API | AWS S3, MinIO, Ceph, Huawei OBS |
| **Container registry** | any OCI registry | Docker Hub, GHCR, Harbor, ECR, Huawei SWR |
| **External access** | LoadBalancer Service or Ingress | any cloud LB / ingress controller |

Two things to know:
- The **shared `ReadWriteMany` filesystem is always required** — Moodle needs a shared data
  directory even when object storage is on.
- **Object storage is optional** but recommended: it moves large files off the shared disk.

## Quickstart

**1. Build and push the image** (from this folder):
```bash
docker buildx build --platform linux/amd64 --provenance=false --sbom=false \
  -t <your-registry>/moodle-objectfs:4.5.11-4 --push .
```

**2. Create a Secret with your credentials:**
```bash
kubectl -n moodle create secret generic moodle-secrets \
  --from-literal=db-password=... \
  --from-literal=redis-password=... \
  --from-literal=admin-password=... \
  --from-literal=s3-access-key=... \
  --from-literal=s3-secret-key=...
```

**3. Install the chart** (edit a values file first with your hosts/bucket):
```bash
helm upgrade --install moodle charts -n moodle --create-namespace \
  --set auth.existingSecret=moodle-secrets -f examples/aws-eks/values.yaml
```

> `charts/values.yaml` is the cloud-agnostic default with every option documented inline.
> [`examples/`](examples/) has ready overlays (AWS, Huawei CCE) — copy the closest one for your cloud.

Full configuration reference and a local test (with MinIO standing in for object storage)
are in **[charts/README.md](charts/README.md)**.

## Documentation

Start here and follow the link for what you need:

| Doc | What's in it |
|---|---|
| **[charts/README.md](charts/README.md)** | Full chart configuration reference + local end-to-end test |
| **[examples/](examples/)** | Ready cloud overlays — [Huawei CCE](examples/huawei-cce/) · [AWS EKS](examples/aws-eks/). Each includes its required `ReadWriteMany` StorageClass + a prerequisites README. |
| **[docs/cdn.md](docs/cdn.md)** | **CDN file delivery** (optional): *why* it's needed, architecture, step-by-step CDN console setup, and operations/troubleshooting |
| **[local/objectfs_cdntoken/](local/objectfs_cdntoken/README.md)** | The provider-neutral CDN token-auth signer plugin (how it extends ObjectFS, supported CDNs) |
| **[CONTRIBUTING.md](CONTRIBUTING.md)** | Dev setup, running tests, conventions |

## Good to know

- **Moodle 4.5 LTS** — the ObjectFS plugin officially supports 4.5, not Moodle 5.x yet.
- **Huawei SWR** can't read the extra metadata `docker buildx` adds by default — that's why
  the build command includes `--provenance=false --sbom=false`.
- **On Huawei CCE Turbo**, allow the pod network range (e.g. `172.16.0.0/12`) in your
  database/Redis firewall rules — pods connect with their own IPs.
- **Optional CDN delivery** — serve user files through a CDN in front of a *private*
  object-storage bucket (edge-cached, token-signed, expiring). Off by default; see
  **[docs/cdn.md](docs/cdn.md)**.

## Contributing

Contributions are welcome — see **[CONTRIBUTING.md](CONTRIBUTING.md)**. Please keep the chart
cloud-agnostic and never commit secrets or real infrastructure details.

## License

[Apache License 2.0](LICENSE).
