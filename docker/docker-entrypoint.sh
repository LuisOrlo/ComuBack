#!/bin/bash
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

php artisan package:discover --ansi

php artisan config:cache
php artisan route:cache
php artisan view:cache

ADMIN_EXISTS=$(php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class); \$kernel->bootstrap(); echo \App\Models\CuentaSistema::where('username', env('ADMIN_USERNAME', 'admin'))->exists() ? 'true' : 'false';" 2>/dev/null || echo "false")
if [ "$ADMIN_EXISTS" != "true" ]; then
    php artisan db:seed --force
fi

if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/VirtualHost \*:80/VirtualHost \*:${PORT}/" /etc/apache2/sites-enabled/000-default.conf
fi

# Forzar que solo mpm_prefork esté activo (fix necesario en Railway)
a2dismod mpm_event mpm_worker 2>/dev/null || true
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true
a2enmod mpm_prefork
apache2ctl -t

exec apache2-foreground
