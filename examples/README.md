# Example values

Ready-to-copy overlays for common platforms. They use only the chart's **generic
values + your platform's annotations** — there's no provider-specific chart logic.

| File | Target | Storage / LB |
|---|---|---|
| [`values-huawei.yaml`](values-huawei.yaml) | Huawei Cloud CCE | OBS, SFS Turbo, RDS, DCS, ELB |
| [`values-aws.yaml`](values-aws.yaml) | AWS EKS | S3, EFS, RDS, ElastiCache, ALB |

## How to use

1. Copy one and fill in every `REPLACE_*` value (hosts, bucket, registry, cert/ELB id).
2. Create the credential Secret (no secrets live in these files):
   ```bash
   kubectl -n moodle create secret generic moodle-secrets \
     --from-literal=db-password=... --from-literal=redis-password=... \
     --from-literal=admin-password=... \
     --from-literal=s3-access-key=... --from-literal=s3-secret-key=...
   ```
3. Install (run from the repo root):
   ```bash
   helm upgrade --install moodle charts -n moodle --create-namespace \
     -f examples/values-aws.yaml
   ```

## Adding your platform

Copy the closest file and adjust the **StorageClass** (must be `ReadWriteMany`), the
**ingress/service annotations** for your load balancer, and the **object-storage endpoint**.
No changes to the chart templates are needed. See [`../charts/values.yaml`](../charts/values.yaml)
for every available option, documented inline.
