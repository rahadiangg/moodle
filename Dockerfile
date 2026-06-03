# =============================================================================
# Moodle (erseco/alpine-moodle) + Catalyst ObjectFS + local_aws (AWS SDK)
# -----------------------------------------------------------------------------
# Plugin CODE is baked in here (reproducible, survives pod restarts/scaling and
# does NOT depend on GitHub being reachable during autoscale). Plugin SETTINGS
# (bucket, keys, endpoint) are applied at runtime via Moosh from the Helm chart.
#
# Target: Moodle 4.5 LTS (branch 405) — chosen because Catalyst ObjectFS
# officially supports it (supported = [404, 405]); no 5.x branch exists yet.
#
# VERIFIED against erseco/alpine-moodle:v4.5.11 (2026-06):
#   * Moodle source is baked at /var/www/html, OWNED BY nobody:nobody.
#   * 4.5 has NO /public restructure, so plugins go under
#     /var/www/html/{admin/tool,local} (the /public layout is 5.0+ only).
#   * Moosh is bundled at /usr/local/bin/moosh (we do not install it).
#   * config.php is generated at runtime; dataroot=/var/www/moodledata.
# =============================================================================

# Pin to a concrete Moodle version: https://hub.docker.com/r/erseco/alpine-moodle/tags
ARG MOODLE_IMAGE_TAG=v4.5.11
FROM erseco/alpine-moodle:${MOODLE_IMAGE_TAG}

# Plugin branches. ObjectFS MOODLE_404_STABLE declares supported=[404,405] and
# is its default/most-maintained branch. local_aws has no per-version stable
# branch (it is just the AWS SDK); master is the rolling branch. Verify:
#   https://github.com/catalyst/moodle-tool_objectfs/branches
#   https://github.com/catalyst/moodle-local_aws/branches
ARG OBJECTFS_BRANCH=MOODLE_404_STABLE
ARG LOCALAWS_BRANCH=master

# Moodle 4.5 code root (admin/tool, local, lib/setup.php live directly here).
ARG MOODLE_WWWROOT=/var/www/html

USER root
RUN set -eux; \
    apk add --no-cache --virtual .build-deps git; \
    \
    # ObjectFS: redirects Moodle's filedir -> S3-compatible storage (Huawei OBS)
    git clone --depth 1 --branch "${OBJECTFS_BRANCH}" \
        https://github.com/catalyst/moodle-tool_objectfs.git \
        "${MOODLE_WWWROOT}/admin/tool/objectfs"; \
    \
    # local_aws: ships the AWS SDK for PHP that ObjectFS's S3 client needs
    git clone --depth 1 --branch "${LOCALAWS_BRANCH}" \
        https://github.com/catalyst/moodle-local_aws.git \
        "${MOODLE_WWWROOT}/local/aws"; \
    \
    # Drop VCS metadata and build deps; fix ownership to match the rest of the tree
    rm -rf "${MOODLE_WWWROOT}/admin/tool/objectfs/.git" \
           "${MOODLE_WWWROOT}/local/aws/.git"; \
    apk del .build-deps; \
    chown -R nobody:nobody \
        "${MOODLE_WWWROOT}/admin/tool/objectfs" \
        "${MOODLE_WWWROOT}/local/aws"

# Sanity-check at build time that the plugins landed where Moodle will load them.
RUN test -f "${MOODLE_WWWROOT}/admin/tool/objectfs/version.php" \
 && test -d "${MOODLE_WWWROOT}/local/aws/sdk"

# -----------------------------------------------------------------------------
# Templatize the PHP-FPM pool so the Helm chart can tune it per pod (this is the
# connection-storm lever at scale). The base image hardcodes pm.max_children=100;
# the erseco entrypoint runs `envsubst` over www.conf at boot, so we replace the
# literals with ${...} placeholders and ship ENV defaults that preserve current
# behavior. The single quotes + \$ keep the placeholder literal past both the
# Docker parser and the build shell, so envsubst can fill it at runtime.
# -----------------------------------------------------------------------------
USER root
RUN set -eux; \
    conf=/etc/php83/php-fpm.d/www.conf; \
    sed -i \
      -e 's|^pm = .*|pm = \${PHP_FPM_PM}|' \
      -e 's|^pm.max_children = .*|pm.max_children = \${PHP_FPM_MAX_CHILDREN}|' \
      -e 's|^pm.max_requests = .*|pm.max_requests = \${PHP_FPM_MAX_REQUESTS}|' \
      "$conf"; \
    grep -E '^pm(\s*=|\.max_children|\.max_requests)' "$conf"

# Defaults match the stock image (ondemand/100); the chart overrides via env.
ENV PHP_FPM_PM=ondemand \
    PHP_FPM_MAX_CHILDREN=100 \
    PHP_FPM_MAX_REQUESTS=2000

USER nobody

# Build & push from the project root (amd64; cross-build from arm64 Macs).
# Huawei SWR rejects buildx attestation manifests, so disable them:
#   docker buildx build --platform linux/amd64 --provenance=false --sbom=false \
#     -t <registry>/moodle-objectfs:4.5.11-2 --push .
