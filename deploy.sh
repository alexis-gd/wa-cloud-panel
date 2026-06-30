#!/usr/bin/env bash
#
# Deploy de wa-cloud-panel a producción (VPS Ubuntu).
# Correr DESDE la raíz del proyecto en el VPS:
#   cd /var/www/wa-cloud-panel && ./deploy.sh
#
# Hace en orden: mantenimiento ON -> pull -> deps -> build -> migrar ->
# cachear config/rutas -> reiniciar queue -> mantenimiento OFF -> health check.
# El orden importa: migrar SIEMPRE antes de reiniciar la cola.

set -euo pipefail

cd "$(dirname "$0")"

echo "==> [1/9] Modo mantenimiento ON"
php artisan down || true

echo "==> [2/9] git pull"
git pull

echo "==> [3/9] Dependencias PHP (producción)"
composer install --no-dev --optimize-autoloader

echo "==> [4/9] Build frontend (Vite)"
npm ci
npm run build

echo "==> [5/9] Migraciones (antes de reiniciar la cola)"
php artisan migrate --force

echo "==> [6/9] Cache de config y rutas"
php artisan config:cache
php artisan route:cache

echo "==> [7/9] Reiniciar queue worker (Supervisor)"
sudo supervisorctl restart wa-queue:*

echo "==> [8/9] Modo mantenimiento OFF"
php artisan up

echo "==> [9/9] Health check"
curl -fsS https://sender.prestamaz.site/api/health && echo

echo "✅ Deploy completo"
