#!/usr/bin/env bash
# Simulates a restrictive shared host and proves the install + first requests still work:
#   - PHP open_basedir limited to the project directory (no /tmp), TMPDIR unset
#   - MariaDB/MySQL with empty sql_mode and MyISAM as the default engine
# Usage: tools/hosting-compat-check.sh   (needs a local MariaDB reachable as root via socket)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
DB=nexora_compat
PORT=8123
mysql -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb3; GRANT ALL ON $DB.* TO 'nexora'@'127.0.0.1' IDENTIFIED BY 'nexora'; GRANT ALL ON $DB.* TO 'nexora'@'localhost' IDENTIFIED BY 'nexora';"
OLD_MODE="$(mysql -uroot -N -e 'SELECT @@GLOBAL.sql_mode')"
OLD_ENGINE="$(mysql -uroot -N -e 'SELECT @@GLOBAL.default_storage_engine')"
restore() { mysql -uroot -e "SET GLOBAL sql_mode='${OLD_MODE}'; SET GLOBAL default_storage_engine='${OLD_ENGINE}'; DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1 || true; [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null || true; rm -rf "$ROOT/var/compat-cache"; }
trap restore EXIT
mysql -uroot -e "SET GLOBAL sql_mode=''; SET GLOBAL default_storage_engine='MyISAM';"

export APP_ENV=prod APP_SECRET=compat-secret-0123456789abcdef0123456789abcd DATABASE_URL="mysql://nexora:nexora@127.0.0.1:3306/$DB?charset=utf8mb4" \
  MAILER_DSN=null://null APP_PUBLIC_URL="http://127.0.0.1:$PORT" LOCK_DSN="flock://$ROOT/var/lock"
unset TMPDIR
rm -f var/install/installed.lock; rm -rf var/cache/* var/tmp var/lock
PHPD=(-d "open_basedir=$ROOT" -d "sys_temp_dir=")
NEXORA_INSTALL_ADMIN_PASSWORD='Compat-Check-2026-Strong!' php "${PHPD[@]}" bin/console commerce:install --no-interaction --store-name="Compat" --admin-name="Compat" \
  --admin-email=compat@example.test --public-url="http://127.0.0.1:$PORT" --site-mode=hybrid >/tmp/compat-install.log 2>&1 || { cat /tmp/compat-install.log; echo "INSTALL FAILED under restrictive hosting"; exit 1; }
ENGINES="$(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND engine<>'InnoDB'")"
[ "$ENGINES" = "0" ] || { echo "non-InnoDB tables created: $ENGINES"; exit 1; }
php "${PHPD[@]}" -S "127.0.0.1:$PORT" -t public >/tmp/compat-web.log 2>&1 &
SRV=$!
sleep 2
for path in / /admin/login /admin/forgot /favicon.ico; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$path")"
  [ "$code" = "200" ] || { echo "GET $path -> $code under restrictive hosting"; tail -5 /tmp/compat-web.log; exit 1; }
done
echo "hosting-compat-check: OK (open_basedir, non-strict sql_mode, MyISAM default, utf8mb3 database)"
