#!/bin/sh
set -e

# ─── Fix volume permissions ───────────────────────────────────────────────────
# Named Docker volumes are created as root on first run. This ensures
# www-data can write to storage and bootstrap/cache regardless.
#
# The api container runs as root (php-fpm's master spawns a www-data pool) and
# does this. The queue worker and scheduler now run AS www-data (compose
# `user: 82:82`) so that nothing they write is root-owned — that is what broke
# product image uploads on 2026-09-24 — and they cannot chown, so they skip it.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache
fi

# ─── Wait for PostgreSQL ──────────────────────────────────────────────────────
echo "Waiting for database..."
until php artisan db:monitor --databases=pgsql 2>/dev/null; do
    echo "Database not ready - retrying in 2s..."
    sleep 2
done

# ─── Bootstrap Laravel ───────────────────────────────────────────────────────
# Run only if APP_ENV is production to avoid breaking test/dev containers
if [ "$APP_ENV" = "production" ]; then
    # /var/www/public belongs to the image (root), so only the root container
    # can lay the symlink. It is the same link for every container anyway.
    if [ "$(id -u)" = "0" ]; then
        echo "==> Linking public storage..."
        php artisan storage:link --force
    fi

    echo "==> Clearing stale caches (safe before migrate)..."
    php artisan config:clear
    php artisan cache:clear

    # ─── Re-discover packages INTO the volume ─────────────────────────────────
    # bootstrap/cache is a named volume (laravel_bootstrap), so it shadows the
    # package manifest the image built. The volume keeps whatever it held on the
    # day it was created, which means a package added to composer.json later is
    # present in vendor/ and absent from the container: mews/purifier landed in
    # #335 and its migration died on "Target class [purifier] does not exist",
    # stopping the deploy before any container was recreated.
    #
    # Discovery reads vendor/composer/installed.json — which IS from the image —
    # and rewrites the manifest in the volume, so the two agree again on every
    # boot rather than only on the day the volume was made. Non-fatal: a failure
    # here must not be what stops a deploy, which is the whole complaint.
    echo "==> Re-discovering packages (bootstrap/cache is a volume)..."
    php artisan package:discover --ansi || echo "WARN: package:discover failed (non-fatal)"

    echo "==> Running database migrations..."
    php artisan migrate --force

    echo "==> Warming caches..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    echo "==> Syncing roles & permissions..."
    # Command is `permission:sync` (singular). The old `permissions:sync` never
    # existed, so this step silently no-op'd on every boot and logged an error.
    # Kept non-fatal (|| echo) so a sync failure never blocks container start,
    # but no longer swallowed to /dev/null — failures are now visible in logs.
    php artisan permission:sync || echo "WARN: permission:sync failed (non-fatal) — verify the role/permission catalog"

    echo "==> Bootstrap complete."
fi

# ─── Hand off to supervisord (nginx + php-fpm) ───────────────────────────────
exec "$@"