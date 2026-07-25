#!/bin/sh

# Fail fast: a failed step must abort container startup instead of booting misconfigured.
set -e

timestamp() {
  date "+%Y-%m-%d %H:%M:%S"
}

log() {
  echo "$(timestamp) $*"
}

#---------------------------------------------------------------------
# configurations
#---------------------------------------------------------------------

# Startup migrations, disabled with LARAVEL_MIGRATE_ENABLE=0 (mirrors the base image's toggle convention)
# for deployments that migrate out-of-band.
# --isolated takes a cache lock (Redis here) so simultaneously starting replicas run the migrations exactly once;
# the retry loop covers the database still coming up alongside this container.

if [ "${LARAVEL_MIGRATE_ENABLE:-1}" != "1" ]; then
  log "Migrations disabled (LARAVEL_MIGRATE_ENABLE=${LARAVEL_MIGRATE_ENABLE}), skipping"
  exit 0
fi

log "Migrate database started"

tries=0
until php /var/www/html/artisan migrate --force --isolated; do
  tries=$((tries + 1))
  if [ "$tries" -ge 10 ]; then
    log "Database not reachable, giving up"
    exit 1
  fi
  log "Database not ready, retrying ($tries/10)"
  sleep 3
done

log "Migrate database finished"
