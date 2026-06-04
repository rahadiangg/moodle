# Contributing

Thanks for your interest — contributions of all kinds are welcome (chart, image,
docs, examples for other clouds).

## Ground rules
- **Never commit secrets or real infrastructure details** — endpoints, access keys,
  passwords, bucket names, account/org IDs, or kubeconfigs. Use `REPLACE_*`
  placeholders and `existingSecret`. The repo ships only generic examples.
- Keep the chart **cloud-agnostic**: no provider-specific template logic. Provider
  integration (load balancer, storage class, registry, endpoint) goes through plain
  values + annotations. Cloud examples live in `examples/` (e.g. `examples/huawei-cce/`).
- Match the existing style and keep changes focused.

## Develop & test
```bash
# Render + lint the chart
helm lint charts -f <(echo 'auth: {dbPassword: x, adminPassword: x, s3AccessKey: x, s3SecretKey: x}')
helm template moodle charts -f my-values.yaml

# Build the image (from repo root)
docker build -t moodle-objectfs:dev .

# Local end-to-end: throwaway Postgres + Redis + MinIO (S3 stand-in)
# — see charts/README.md "Local end-to-end test".

# Run the CDN signer plugin's PHPUnit suite (throwaway test image + Postgres)
bash scripts/run-plugin-tests.sh
```

Before opening a PR:
- `helm lint charts` passes.
- `helm template` renders across the toggles you touched (e.g. `objectfs.enabled`,
  `ingress.enabled`, `service.type=LoadBalancer`).
- If you touched `local/objectfs_cdntoken/`, `bash scripts/run-plugin-tests.sh` passes.
- Docs/values comments updated for any new/renamed value.

## Adding a cloud example
Add `examples/<cloud>/` with a `values.yaml` (generic values + that cloud's annotations),
the required RWX `StorageClass` manifest, and a short `README.md` for prerequisites
(mirror `examples/huawei-cce/`). Don't add cloud-specific Go templating.

## License
By contributing, you agree your contributions are licensed under the
[Apache License 2.0](LICENSE).
