# Huawei Cloud CCE example

Targets Huawei CCE using only the chart's **generic values + CCE annotations** —
there is no Huawei-specific template logic in the chart.

| File | Purpose |
|---|---|
| [`values.yaml`](values.yaml) | Helm overlay (OBS, SFS Turbo, CDN, RDS, DCS, ELB) |
| [`sfsturbo-subpath-storageclass.yaml`](sfsturbo-subpath-storageclass.yaml) | Shared-storage StorageClass (required prerequisite) |

## Why a StorageClass file is needed

CCE's default `csi-sfsturbo` StorageClass **cannot dynamically create a new SFS
Turbo filesystem** — provisioning fails with `unsupported to create a new
sfsturbo resource, check the value of everest.io/volume-as`. The supported
pattern is to **pre-create one SFS Turbo filesystem** and use a **subpath**
StorageClass that carves a subdirectory into it per PVC (one filesystem backs
many PVCs — also the cost-efficient choice).

## Prerequisites

1. **Object storage (OBS):** create a bucket; note region + endpoint.
2. **Database / cache:** RDS for PostgreSQL and DCS Redis, reachable from the
   cluster. Add the CCE container CIDR (e.g. `172.16.0.0/12`) to their security
   groups, and the DCS IP whitelist.
3. **SFS Turbo filesystem (shared moodledata):**
   - Create it in the **same VPC** as the cluster nodes, **≥ 500 GiB** (STANDARD
     minimum), type STANDARD or PERFORMANCE.
   - Open its security group to the node subnet for NFS: **TCP+UDP 2049, 111, 20048**.
   - Copy the **File system ID** (UUID) and **mount address** (e.g. `192.168.0.2:/`).

## Install

```bash
# 1. StorageClass — fill the two REPLACE_ values first
kubectl apply -f examples/huawei-cce/sfsturbo-subpath-storageclass.yaml

# 2. Credential Secret (no secrets live in the values file)
#    Include cdn-signing-key ONLY if objectfs.cdn.enabled (it is, in this example).
kubectl -n moodle create secret generic moodle-secrets --create-namespace \
  --from-literal=db-password=... --from-literal=redis-password=... \
  --from-literal=admin-password=... \
  --from-literal=s3-access-key=... --from-literal=s3-secret-key=... \
  --from-literal=cdn-signing-key=...

# 3. Fill the REPLACE_* values in values.yaml, then install (from repo root)
helm upgrade --install moodle charts -n moodle --create-namespace \
  -f examples/huawei-cce/values.yaml
```

## CDN delivery (Huawei CDN Token Auth, Method A)

This example enables `objectfs.cdn` so file downloads (PDF/Word/Excel/media) are
served from **Huawei Cloud CDN** in front of the **private** OBS bucket — cached
at the edge, with the OBS bucket staying private and links expiring.

> Full rationale, architecture, and operations/troubleshooting are in
> **[docs/cdn.md](../../docs/cdn.md)**. The checklist below is the Huawei-specific quick path.

**Image requirement:** use the **`-cdntoken`** image variant (built with
`--build-arg INCLUDE_CDN_PLUGIN=true`). The base image lacks the signer plugin
and pods will fail to boot if `objectfs.cdn.enabled` is true.

```bash
# Build both variants from the one Dockerfile (run from repo root):
docker buildx build --provenance=false --sbom=false \
  -t <reg>/moodle-objectfs:4.5.11-4 --push .
docker buildx build --provenance=false --sbom=false --build-arg INCLUDE_CDN_PLUGIN=true \
  -t <reg>/moodle-objectfs:4.5.11-4-cdntoken --push .
```

**CDN console checklist** (the chart cannot do this — it's cloud config):
1. Create the acceleration domain (`objectfs.cdn.domain`) + bind an HTTPS cert; force HTTPS.
2. Origin = the OBS bucket; turn **OBS Pull Authentication ON** (bucket stays private).
3. Enable **Token Authentication, Signing Method A**: signing key == the Secret's
   `cdn-signing-key`; validity window == `objectfs.cdn.validity` (1800s here);
   parameter name == `auth_key`; **Encryption Algorithm == `objectfs.cdn.algorithm`**
   (SHA256 recommended — must match exactly or every request 403s).
4. **Cache key: IGNORE the `auth_key` parameter** — otherwise every user's signed
   URL is a distinct cache key and the cache (and egress offload) is defeated.
5. Long cache TTL for the object path prefix (content-hash objects are immutable).
6. Enable Range/byte-range origin pull (video scrubbing).

**How access control is preserved:** Moodle checks the user's capability in
`pluginfile.php` *before* redirecting, so an unauthorized user never receives a
CDN URL. The link then expires after the CDN validity window.

## Verify shared storage (multi-pod, cross-node)

With `web.replicaCount >= 2` and `web.podAntiAffinity: hard`, the web pods land
on different nodes and share one moodledata volume:

```bash
kubectl get pods -n moodle -o wide          # web pods on different nodes; all Running
kubectl get pvc moodle-moodledata -n moodle # Bound / ReadWriteMany / csi-sfsturbo-subpath
```
A file uploaded through the Moodle UI on one pod is immediately served by the
other — proving the dataroot is genuinely shared (not per-pod).
