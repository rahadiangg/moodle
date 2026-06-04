# AWS EKS example

Targets AWS EKS using only the chart's **generic values + AWS annotations** —
there is no AWS-specific template logic in the chart.

| File | Purpose |
|---|---|
| [`values.yaml`](values.yaml) | Helm overlay (S3, EFS, RDS, ElastiCache, ALB) |
| [`efs-storageclass.yaml`](efs-storageclass.yaml) | Shared-storage StorageClass (required prerequisite) |

## Why a StorageClass file is needed

Moodle needs a shared dataroot across all web/cron pods, so the volume must be
**ReadWriteMany**. On EKS that means **Amazon EFS** via the EFS CSI driver —
EBS/gp3 is RWO and won't mount on multiple nodes.

## Prerequisites

1. **Object storage (S3):** create a bucket; note the region.
2. **Database / cache:** RDS for PostgreSQL and ElastiCache for Redis, reachable
   from the cluster (security groups allow the node/pod CIDRs).
3. **EFS (shared moodledata):**
   - Install the **AWS EFS CSI driver** (EKS add-on `aws-efs-csi-driver`).
   - Create an **EFS filesystem** in the cluster VPC with **mount targets in each
     node subnet**, security group allowing **NFS (TCP 2049)** from the nodes.
   - Put the filesystem ID (`fs-xxxx`) into `efs-storageclass.yaml`.

## Install

```bash
# 1. StorageClass — fill REPLACE_EFS_FILESYSTEM_ID first
kubectl apply -f examples/aws-eks/efs-storageclass.yaml

# 2. Credential Secret (no secrets live in the values file)
kubectl -n moodle create secret generic moodle-secrets --create-namespace \
  --from-literal=db-password=... --from-literal=redis-password=... \
  --from-literal=admin-password=... \
  --from-literal=s3-access-key=... --from-literal=s3-secret-key=...

# 3. Fill the REPLACE_* values in values.yaml, then install (from repo root)
helm upgrade --install moodle charts -n moodle --create-namespace \
  -f examples/aws-eks/values.yaml
```
