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

# Cache config, routes, views and events against the runtime environment.
# Runs at startup, not at image build: config:cache freezes env() values, and the real environment only exists here.
# This also boots the app, so the environment security assertions (EnvironmentSecurityChecks) fire first.
# A misconfigured container dies here with the full failure list in the logs.

log "Laravel optimize started"

php /var/www/html/artisan optimize

log "Laravel optimize finished"
