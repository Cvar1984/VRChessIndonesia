# Production image for Railway (or any Docker host). FrankenPHP bundles a
# Caddy web server + PHP 8.3 in one binary/process — simpler to containerize
# correctly than coordinating separate nginx + php-fpm processes, and it's
# what Symfony's own official Docker recipe uses.
FROM dunglas/frankenphp:latest-php8.3

# Stockfish engine binary — apt puts it at /usr/games/stockfish on Debian
# (verified locally; not /usr/bin/stockfish like this project's dev machine).
RUN apt-get update \
    && apt-get install -y --no-install-recommends stockfish \
    && rm -rf /var/lib/apt/lists/*

# mongodb: required by doctrine/mongodb-odm. Verified locally that the
# regular (non-static-binary) FrankenPHP image supports this fine via the
# bundled install-php-extensions script — the "mongodb isn't included"
# limitation reported for FrankenPHP only applies to its separate static
# musl binary distribution, not this Docker image.
RUN install-php-extensions mongodb intl opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies before copying the full source so this layer is
# cached across builds that only change application code.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# This image's one genuinely environment-specific default: apt's stockfish
# package installs to /usr/games/stockfish on Debian, not /usr/bin/stockfish
# like the dev machine (or example.env) assume. Not a secret, so it's safe
# as a permanent image default — Railway never needs to know this path.
ENV APP_ENV=prod \
    STOCKFISH_BINARY=/usr/games/stockfish

# Symfony's Dotenv component requires a base .env file to physically exist
# even when every real value actually arrives via real process env vars —
# example.env (the repo's already-committed, secret-free placeholder
# template) is copied in to satisfy that. Real secrets (MONGODB_URI,
# APP_SECRET, ADMIN_PASSWORD, VRCHAT_*) are Railway env vars injected at
# container start; Symfony's Dotenv never overrides a value that's already
# a real env var, so these placeholders only matter if Railway's vars are
# ever missing (see docker/start.sh, which re-warms the cache once the
# container starts with the real values already present).
RUN cp example.env .env \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php bin/console asset-map:compile --env=prod --no-interaction

RUN chmod +x docker/start.sh

EXPOSE 80

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["docker/start.sh"]
