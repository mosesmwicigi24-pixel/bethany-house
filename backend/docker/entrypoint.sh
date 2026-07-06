#!/bin/sh
set -e

# ─── Fix volume permissions ───────────────────────────────────────────────────
# Named Docker volumes are created as root on first run. This ensures
# www-data can write to storage and bootstrap/cache regardless.
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# ─── Wait for PostgreSQL ──────────────────────────────────────────────────────
echo "Waiting for database..."
until php artisan db:monitor --databases=pgsql 2>/dev/null; do
    echo "Database not ready - retrying in 2s..."
    sleep 2
done

# ─── Bootstrap Laravel ───────────────────────────────────────────────────────
# Run only if APP_ENV is production to avoid breaking test/dev containers
if [ "$APP_ENV" = "production" ]; then
    echo "==> Linking public storage..."
    php artisan storage:link --force

    echo "==> Clearing stale caches (safe before migrate)..."
    php artisan config:clear
    php artisan cache:clear

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