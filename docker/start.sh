#!/bin/sh
set -e

# Railway assigns a dynamic $PORT at runtime (not known at build time). The
# default FrankenPHP Caddyfile listens on {$SERVER_NAME:localhost} — point
# it at Railway's port. Falls back to 80 for non-Railway hosts.
export SERVER_NAME=":${PORT:-80}"

# The image was built with placeholder secrets (see Dockerfile) just so the
# container could compile at all — re-warm now that Railway's real env vars
# are actually present, discarding whatever compiled with the placeholders.
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

# Daily VRChat avatar refresh. Runs once at boot, then every 24h; the command
# skips avatars cached <24h ago, so frequent redeploys don't re-hit VRChat.
# ponytail: sleep loop in the web container, one per replica — move to a
# Railway cron service if this ever runs with more than one replica.
(while true; do
    php bin/console app:vrchat:refresh-avatars --env=prod || true
    sleep 86400
done) &

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
