#!/bin/sh
# Hastra container entrypoint.
#   1. Keys: every config/*.key file lives in /data/keys. The four encryption
#      keys come from HASTRA_DB_KEY / HASTRA_CRYPTO_KEY / HASTRA_SECRET_KEY /
#      HASTRA_WEBHOOK_KEY when set (required where /data/keys is not a
#      persistent disk, such as Render's free plan); otherwise they are
#      generated on first use and the hastra-keys volume MUST be backed up.
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
# Encryption keys from the environment. A host without a persistent disk
# (Render's free plan) starts every deploy and every restart with an empty
# /data/keys, so without these the app would mint new keys each time and
# every name, email and token already in the database would become
# unreadable. Each value is 32 random bytes, base64-encoded:
#   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
# Set them once and never change them (see DEPLOY.md).
put_key() { # $1 = env var name, $2 = key file; returns 1 when the variable is unset
  v=$(printenv "$1" || true)
  [ -n "$v" ] || return 1
  if ! printf '%s' "$v" | php -r '$r = base64_decode(trim(stream_get_contents(STDIN)), true); exit($r !== false && strlen($r) === 32 ? 0 : 1);'; then
    echo "hastra: $1 must be 32 random bytes, base64-encoded (44 characters). Refusing to start rather than guess a key."
    exit 1
  fi
  echo "$v" > "$KEYS/$2"
}
missing=""
put_key HASTRA_DB_KEY      astra_db.key              || missing="$missing HASTRA_DB_KEY"
put_key HASTRA_CRYPTO_KEY  astra_index.key           || missing="$missing HASTRA_CRYPTO_KEY"
put_key HASTRA_SECRET_KEY  astra.key                 || missing="$missing HASTRA_SECRET_KEY"
put_key HASTRA_WEBHOOK_KEY astra_payment_webhook.key || missing="$missing HASTRA_WEBHOOK_KEY"
if [ -n "$missing" ]; then
  echo "hastra: WARNING - encryption keys not set in the environment:$missing"
  echo "hastra: WARNING - on a host without a persistent disk they are regenerated on every deploy/restart,"
  echo "hastra: WARNING - which makes existing accounts unreadable. Set them (DEPLOY.md, 'Encryption keys')."
fi

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
# One-off cleanup of accounts that became unreadable after lost keys. Opt-in:
# HASTRA_PURGE_UNREADABLE=dry previews in this log, =1 applies. Remove the
# variable afterwards. Never blocks startup.
case "${HASTRA_PURGE_UNREADABLE:-}" in
  dry) ( cd "$APP" && php tools/purge_unreadable.php ) || true ;;
  1)   ( cd "$APP" && php tools/purge_unreadable.php --apply ) || true ;;
esac
# key files the migrations just generated must stay readable by Apache (www-data)
chown -R www-data:www-data "$KEYS"
find "$KEYS" -type f -exec chmod 600 {} +

exec docker-php-entrypoint "$@"
