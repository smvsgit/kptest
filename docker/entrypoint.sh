#!/bin/bash
set -Eeuo pipefail

cd /var/www/html

# APP_KEY may be provided by Coolify. If omitted on a brand-new resource,
# create one once and keep it in the persistent app-storage volume. Never log it.
KEY_FILE="/var/www/html/storage/app/.runtime-app-key"
if [ -z "${APP_KEY:-}" ]; then
    if [ -s "$KEY_FILE" ]; then
        APP_KEY="$(cat "$KEY_FILE")"
        export APP_KEY
        echo "APP_KEY source: persistent app-storage"
    else
        APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
        export APP_KEY
        mkdir -p "$(dirname "$KEY_FILE")"

        # Keep the APP_KEY file private without changing the parent shell's umask.
        # v13.04 used a process-wide `umask 077` here, which leaked into the later
        # Laravel cache commands. Those commands then created root-owned 0600 files
        # such as bootstrap/cache/config.php, and Apache (www-data) could not read
        # them, causing /up and all web requests to fail with Permission denied.
        (
            umask 077
            printf '%s\n' "$APP_KEY" > "$KEY_FILE"
        )
        chown www-data:www-data "$KEY_FILE" || true
        chmod 600 "$KEY_FILE" || true
        echo "APP_KEY source: generated for this fresh resource and stored persistently"
    fi
else
    echo "APP_KEY source: Coolify environment"
fi

# Runtime-generated Laravel caches must be readable by Apache. Make this explicit
# even if the container/runtime supplies a more restrictive inherited umask.
umask 022

# Ensure writable dirs exist (volumes/bind mounts can start empty on first deploy).
mkdir -p storage/app/media/uploads/{images,videos,audios,documents} \
         storage/app/media/thumbnails \
         storage/app/private/chunks \
         storage/framework/{cache/data,sessions,views} \
         storage/logs \
         bootstrap/cache

# Recursive chown only on small framework state. The uploads bind mount may hold
# many media files, so only normalize its top-level directories here.
chown -R www-data:www-data storage/framework storage/logs storage/app/private bootstrap/cache
chown www-data:www-data storage storage/app storage/app/public storage/app/media \
     storage/app/media/uploads storage/app/media/uploads/* storage/app/media/thumbnails

# Symlink public/storage -> storage/app/public (volume-safe: recreate every boot).
rm -rf public/storage
php artisan storage:link --force || php artisan storage:link || true

# New Coolify resource + new db-data volume => empty MariaDB => full migration set.
# Existing resource/redeploy => only pending migrations. No destructive reset is used.
echo "Waiting for database and applying pending migrations..."
tries=0
until php artisan migrate --force; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "ERROR: database/migration step failed after 30 attempts. Refusing to start Apache."
        exit 1
    fi
    sleep 2
done

# Fresh DB: creates exactly one Super Admin from Coolify secrets.
# Existing DB: idempotent; does not reset the existing password.
php artisan db:seed --class='Database\Seeders\DatabaseSeeder' --force

# Rebuild caches with the runtime environment.
php artisan package:discover --ansi || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Cache commands run as root during container startup. Normalize ownership and
# modes afterward so Apache can always read the generated bootstrap cache and can
# refresh framework view/cache state when needed.
chown -R www-data:www-data bootstrap/cache storage/framework/views
find bootstrap/cache -type d -exec chmod 0775 {} +
find bootstrap/cache -type f -exec chmod 0644 {} +
find storage/framework/views -type d -exec chmod 0775 {} +
find storage/framework/views -type f -exec chmod 0664 {} +

exec "$@"
