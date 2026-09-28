#!/bin/sh
# Hastra container entrypoint.
#   1. Keys: every config/*.key file lives in the persistent /data/keys volume.
#      The encryption keys are generated on first use; if they were kept in
#      the image, a rebuild would lose them and every encrypted column would
#      become unreadable for good. BACK UP THE hastra-keys VOLUME.
#   2. Secrets given in the environment are written to their key files.
#   3. Waits for the database, creates the schema on an empty one, then runs
#      every migration (all idempotent) so an upgrade needs only a rebuild.
set -eu
APP=/var/www/html
KEYS=/data/keys

mkdir -p "$KEYS"
for k in astra.key astra_db.key astra_index.key astra_payment_webhook.key \
         astra_db.v2.key astra_db.v3.key astra_db.v4.key astra_db.v5.key astra_db.v6.key astra_db.v7.key astra_db.v8.key \
         google_oauth.key recaptcha.key smtp.key youtube.key anthropic.key db_password.key; do
  rm -f "$APP/config/$k"
  ln -s "$KEYS/$k" "$APP/config/$k"
done

# secrets from the environment → key files (so .env is the one place to set them)
put_secret() { # $1 = env var name, $2 = key file
  eval "v=\${$1:-}"
  if [ -n "$v" ]; then printf '%s\n' "$v" > "$KEYS/$2"; fi
}
put_secret HASTRA_GOOGLE_CLIENT_SECRET google_oauth.key
put_secret HASTRA_RECAPTCHA_SECRET recaptcha.key
put_secret HASTRA_YOUTUBE_API_KEY youtube.key
put_secret HASTRA_ANTHROPIC_API_KEY anthropic.key
chown -R www-data:www-data "$KEYS" "$APP/uploads"
chmod 700 "$KEYS"
find "$KEYS" -type f -exec chmod 600 {} +

# the app reads its own names for these two
export ASTRA_RECAPTCHA_SECRET="${HASTRA_RECAPTCHA_SECRET:-${ASTRA_RECAPTCHA_SECRET:-}}"
[ -n "${HASTRA_GOOGLE_CLIENT_ID:-}" ] && export ASTRA_GOOGLE_CLIENT_ID="$HASTRA_GOOGLE_CLIENT_ID"

# A managed database (Aiven, PlanetScale, etc.) needs a non-default port and
# TLS; the self-hosted `db` container needs neither (HASTRA_DB_SSL_CA unset).
DB_PORT="${HASTRA_DB_PORT:-3306}"
DB_TLS_ARGS=""
[ -n "${HASTRA_DB_SSL_CA:-}" ] && DB_TLS_ARGS="--ssl-ca=${HASTRA_DB_SSL_CA}"

echo "hastra: waiting for the database at ${HASTRA_DB_HOST:-db}:${DB_PORT}…"
i=0
until mariadb-admin ping -h"${HASTRA_DB_HOST:-db}" -P"$DB_PORT" -u"${HASTRA_DB_USER}" -p"${HASTRA_DB_PASS}" $DB_TLS_ARGS --silent 2>/dev/null; do
  i=$((i + 1)); [ "$i" -gt 60 ] && { echo "hastra: database never came up"; exit 1; }
  sleep 2
done

tables=$(mariadb -h"${HASTRA_DB_HOST:-db}" -P"$DB_PORT" -u"${HASTRA_DB_USER}" -p"${HASTRA_DB_PASS}" $DB_TLS_ARGS -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${HASTRA_DB_NAME}' AND table_name='users'")
if [ "$tables" = "0" ]; then
  echo "hastra: empty database, creating the schema"
  mariadb -h"${HASTRA_DB_HOST:-db}" -P"$DB_PORT" -u"${HASTRA_DB_USER}" -p"${HASTRA_DB_PASS}" $DB_TLS_ARGS "${HASTRA_DB_NAME}" < "$APP/docker/schema.sql"
fi

echo "hastra: running migrations"
( cd "$APP" && php tools/run_migrations.php ) || { echo "hastra: migrations failed"; exit 1; }
# key files the migrations just generated must stay readable by Apache (www-data)
chown -R www-data:www-data "$KEYS"
find "$KEYS" -type f -exec chmod 600 {} +

exec docker-php-entrypoint "$@"
