# Contributing

Thanks for your interest — contributions of all kinds are welcome (chart, image,
docs, examples for other clouds).

## Ground rules
- **Never commit secrets or real infrastructure details** — endpoints, access keys,
  passwords, bucket names, account/org IDs, or kubeconfigs. Use `REPLACE_*`
  placeholders and `existingSecret`. The repo ships only generic examples.
- Keep the chart **cloud-agnostic**: no provider-specific template logic. Provider
  integration (load balancer, storage class, registry, endpoint) goes through plain
  values + annotations. Cloud examples live in `examples/` (e.g. `examples/values-huawei.yaml`).
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
```

Before opening a PR:
- `helm lint charts` passes.
- `helm template` renders across the toggles you touched (e.g. `objectfs.enabled`,
  `ingress.enabled`, `service.type=LoadBalancer`).
- Docs/values comments updated for any new/renamed value.

## Adding a cloud example
Add `examples/values-<cloud>.yaml` using only generic values + that cloud's annotations
(mirror `examples/values-huawei.yaml`). Don't add cloud-specific Go templating.

## License
By contributing, you agree your contributions are licensed under the
[Apache License 2.0](LICENSE).
