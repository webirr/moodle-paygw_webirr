#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

MOODLE_PORT="${MOODLE_PORT:-8097}"
MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_502_STABLE}"
MOODLE_ADMIN_PASSWORD="${MOODLE_ADMIN_PASSWORD:-AdminDemo1!}"
WEBIRR_MOODLE_DEMO_USERNAME="${WEBIRR_MOODLE_DEMO_USERNAME:-webirrstudent}"
WEBIRR_MOODLE_DEMO_PASSWORD="${WEBIRR_MOODLE_DEMO_PASSWORD:-WebirrDemo1!}"

if [[ -z "${WEBIRR_TEST_ENV_MERCHANT_ID:-}" || -z "${WEBIRR_TEST_ENV_API_KEY:-}" ]]; then
  echo "WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY are required." >&2
  exit 1
fi

mkdir -p .runtime

if [[ ! -d .runtime/moodle-src/.git ]]; then
  if [[ -n "${MOODLE_SOURCE_CACHE:-}" && -d "${MOODLE_SOURCE_CACHE}/.git" ]]; then
    echo "Copying Moodle source from MOODLE_SOURCE_CACHE..."
    mkdir -p .runtime/moodle-src
    rsync -a --delete "${MOODLE_SOURCE_CACHE}/" .runtime/moodle-src/
  else
    echo "Cloning Moodle ${MOODLE_BRANCH}..."
    git clone --depth 1 --branch "$MOODLE_BRANCH" https://github.com/moodle/moodle.git .runtime/moodle-src
  fi
fi

mkdir -p .runtime/moodledata
chmod 777 .runtime/moodledata

cat > .runtime/moodle-src/config.php <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

\$CFG->dbtype    = 'pgsql';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = 'postgres';
\$CFG->dbname    = 'moodle';
\$CFG->dbuser    = 'moodle';
\$CFG->dbpass    = 'moodle';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = [
    'dbpersist' => 0,
    'dbport' => '',
    'dbsocket' => '',
];

\$CFG->wwwroot   = 'http://localhost:${MOODLE_PORT}';
\$CFG->dataroot  = '/var/www/moodledata';
\$CFG->phpunit_dataroot = '/var/www/phpunitdata';
\$CFG->phpunit_prefix = 'phpu_';
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 02777;
\$CFG->debug = (E_ALL | E_STRICT);
\$CFG->debugdisplay = 1;

require_once(__DIR__ . '/lib/setup.php');
PHP

docker compose up -d postgres web

echo "Waiting for Moodle web container..."
for _ in {1..60}; do
  if docker compose exec -T web test -f /var/www/html/admin/cli/install_database.php >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! docker compose exec -T web php /var/www/html/admin/cli/cfg.php --name=release >/dev/null 2>&1; then
  docker compose exec -T web php /var/www/html/admin/cli/install_database.php \
    --agree-license \
    --fullname="WeBirr Moodle Test" \
    --shortname="WeBirr Test" \
    --adminpass="$MOODLE_ADMIN_PASSWORD" \
    --adminemail="admin@example.com"
fi

docker compose exec -T web php /var/www/html/admin/cli/upgrade.php --non-interactive
docker compose exec -T \
  web php /var/www/html/webirr-demo-scripts/seed.php
docker compose exec -T web php /var/www/html/admin/cli/purge_caches.php

echo "Moodle checkout example is ready:"
echo "  http://localhost:${MOODLE_PORT}/course/view.php?name=WEBIRR-CHECKOUT"
echo "Demo login:"
echo "  username: ${WEBIRR_MOODLE_DEMO_USERNAME}"
