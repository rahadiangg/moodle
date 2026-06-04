#!/usr/bin/env bash
# Run the local_objectfs_cdntoken PHPUnit suite in a throwaway test image +
# ephemeral Postgres. The production image is untouched. Usage (from repo root):
#   bash scripts/run-plugin-tests.sh [extra phpunit args...]
set -euo pipefail
cd "$(dirname "$0")/.."

IMAGE=moodle-cdntoken-test
NET=cdntoken-test-net
PG=cdntoken-test-pg
WWW=/var/www/html

cleanup() {
  docker rm -f "$PG" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> build test image (--target test)"
docker build --target test -t "$IMAGE" .

echo "==> start ephemeral Postgres"
docker network create "$NET" >/dev/null
docker run -d --name "$PG" --network "$NET" \
  -e POSTGRES_USER=moodle -e POSTGRES_PASSWORD=moodle -e POSTGRES_DB=moodle_test \
  postgres:16-alpine >/dev/null

echo "==> wait for Postgres"
for _ in $(seq 1 60); do
  docker exec "$PG" pg_isready -U moodle -d moodle_test >/dev/null 2>&1 && break
  sleep 1
done

echo "==> init test DB + run PHPUnit"
# config.php is written inside the container; $ is escaped so the CONTAINER shell
# does not expand it (PHP reads PGHOST from the env we pass in).
# --entrypoint /bin/sh REPLACES the erseco image's Moodle-startup entrypoint so our
# script runs directly (otherwise the base entrypoint hangs configuring Moodle).
docker run --rm --network "$NET" -e PGHOST="$PG" --entrypoint /bin/sh "$IMAGE" -euc '
cat > '"$WWW"'/config.php <<PHP
<?php  // Moodle test configuration (ephemeral).
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype    = "pgsql";
\$CFG->dblibrary = "native";
\$CFG->dbhost    = getenv("PGHOST");
\$CFG->dbname    = "moodle_test";
\$CFG->dbuser    = "moodle";
\$CFG->dbpass    = "moodle";
\$CFG->prefix    = "mdl_";
\$CFG->dboptions = ["dbpersist" => 0, "dbport" => 5432, "dbsocket" => ""];
\$CFG->wwwroot   = "http://localhost";
\$CFG->dataroot  = "/var/www/moodledata-test";
\$CFG->admin     = "admin";
\$CFG->directorypermissions = 02777;
// PHPUnit: separate test prefix + dataroot (this whole DB is ephemeral anyway).
\$CFG->phpunit_prefix   = "t_";
\$CFG->phpunit_dataroot = "/var/www/phpunit_dataroot";
require_once(__DIR__ . "/lib/setup.php");
PHP
mkdir -p /var/www/moodledata-test /var/www/phpunit_dataroot
chmod -R 0777 /var/www/moodledata-test /var/www/phpunit_dataroot
php '"$WWW"'/admin/tool/phpunit/cli/init.php
cd '"$WWW"'
vendor/bin/phpunit -c local/objectfs_cdntoken '"$*"'
'
echo "==> PASSED"
