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

# Security configuration diagnostics (docs/hardening.md): itemizes hard failures
# (exit 1 aborts startup - broken captcha keys, missing Vite build, leftover hot file) and logs the softer topology warnings
# (proxy/host coherence, HSTS/CSP rollout state) before the container starts serving.
# Runs before migrations so a misconfigured deploy never touches the database.

log "Security diagnose started"

php /var/www/html/artisan security:diagnose

log "Security diagnose finished"
