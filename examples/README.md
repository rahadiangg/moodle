# Example values

Ready-to-copy overlays for common platforms. They use only the chart's **generic
values + your platform's annotations** — there's no provider-specific chart logic.

Each platform has its own folder with a Helm overlay, the required
**ReadWriteMany StorageClass** manifest, and a README covering prerequisites.

| Folder | Target | Storage / LB |
|---|---|---|
| [`huawei-cce/`](huawei-cce/) | Huawei Cloud CCE | OBS, SFS Turbo, RDS, DCS, ELB |
| [`aws-eks/`](aws-eks/) | AWS EKS | S3, EFS, RDS, ElastiCache, ALB |

## How to use

Open the folder for your platform and follow its README — each lists the
prerequisites (object storage, DB/cache, shared filesystem) and the exact
install steps. In short:

1. Apply the folder's **StorageClass** manifest (fill its `REPLACE_*` first) —
   shared moodledata must be `ReadWriteMany`.
2. Create the credential Secret (no secrets live in these files):
   ```bash
   kubectl -n moodle create secret generic moodle-secrets --create-namespace \
     --from-literal=db-password=... --from-literal=redis-password=... \
     --from-literal=admin-password=... \
     --from-literal=s3-access-key=... --from-literal=s3-secret-key=...
   ```
3. Fill every `REPLACE_*` in the folder's `values.yaml`, then install from the repo root:
   ```bash
   helm upgrade --install moodle charts -n moodle --create-namespace \
     -f examples/aws-eks/values.yaml
   ```

## Adding your platform

Copy the closest folder and adjust the **StorageClass** (must be `ReadWriteMany`), the
**ingress/service annotations** for your load balancer, and the **object-storage endpoint**.
No changes to the chart templates are needed. See [`../charts/values.yaml`](../charts/values.yaml)
for every available option, documented inline.
