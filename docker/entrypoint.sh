#!/bin/sh
set -eu

cd /var/www

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY must be configured before the container starts." >&2
    exit 1
fi

# A persistent volume mounted at storage/ shadows the tree baked into the image,
# so the skeleton has to be recreated on every boot rather than only at build time.
mkdir -p \
    bootstrap/cache \
    storage/app/backup-temp \
    storage/app/private/backups \
    storage/app/public \
    storage/app/tus \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data storage bootstrap/cache
chmod -R u=rwX,g=rwX,o=rX storage bootstrap/cache

# Nginx reads this map before it starts. FRONTEND_URLS is comma-separated.
write_frontend_cors_map() {
    dest=/etc/nginx/tus-origins.conf
    tmp="${dest}.tmp"
    printf '%s\n' 'map $http_origin $frontend_cors_origin {' > "$tmp"
    printf '%s\n' '    default "";' >> "$tmp"
    seen="|"
    list=$(printf '%s,%s' "${FRONTEND_URLS:-}" "${FRONTEND_URL:-}")
    old_ifs=$IFS
    IFS=','
    for origin in $list; do
        origin=$(printf '%s' "$origin" | tr -d '[:space:]')
        origin=${origin%/}
        case $origin in
            http://*|https://*) ;;
            *) continue ;;
        esac
        case $seen in
            *"|${origin}|"*) continue ;;
        esac
        seen="${seen}${origin}|"
        printf '    "%s" $http_origin;\n' "$origin" >> "$tmp"
    done
    IFS=$old_ifs
    printf '%s\n' '}' >> "$tmp"
    mv "$tmp" "$dest"
}

write_frontend_cors_map

# Cache here, not in the Dockerfile. Coolify injects environment variables into
# the running container, so caching during the build would freeze placeholders
# into bootstrap/cache where they outrank the real values at runtime.
su -s /bin/sh www-data -c 'php artisan config:cache --no-interaction'
# Pending migrations only. Without this, a new table (videos, media) 500s
# in production while the route itself is already registered.
su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'
su -s /bin/sh www-data -c 'php artisan route:cache --no-interaction'
su -s /bin/sh www-data -c 'php artisan view:cache --no-interaction'

# Recreate public/storage; the symlink points into the volume and may be stale
# or missing after the image is rebuilt.
su -s /bin/sh www-data -c 'php artisan storage:link --force --no-interaction'

exec "$@"
