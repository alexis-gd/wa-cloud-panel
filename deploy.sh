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

echo "==> [1/10] Modo mantenimiento ON"
php artisan down || true

echo "==> [2/10] git pull"
git pull

echo "==> [3/10] Dependencias PHP (producción)"
composer install --no-dev --optimize-autoloader

echo "==> [4/10] Build frontend (Vite)"
npm ci
npm run build

echo "==> [5/10] Migraciones (antes de reiniciar la cola)"
php artisan migrate --force

echo "==> [6/10] Cache de config y rutas"
php artisan config:cache
php artisan route:cache

echo "==> [7/10] Reiniciar queue worker (Supervisor)"
sudo supervisorctl restart wa-queue:*

echo "==> [8/10] Modo mantenimiento OFF"
php artisan up

echo "==> [9/10] Health check"
curl -fsS https://sender.prestamaz.site/api/health && echo

# El cron NO lo instala este script (necesita root y es montaje del servidor, no del
# despliegue), pero sí se revisa: una sola línea de crontab mueve todo lo automático del
# sistema - alta de contactos, warm-up, marcado de inalcanzables y reconciliaciones de SMS.
# Si se rompe, nada se ve mal y el sistema se desafina en silencio durante días.
echo "==> [10/10] Latido del programador de tareas"
LATIDO=$(php artisan tinker --execute="echo json_encode(App\Services\System\SchedulerHeartbeat::estado());" 2>/dev/null | tail -1)

if echo "$LATIDO" | grep -q '"never_ran":true'; then
    echo "   Sin latido todavía. Es normal recién desplegado: el cron tarda hasta un minuto."
    echo "   Vuelve a revisar en un minuto con:"
    echo "     php artisan tinker --execute=\"print_r(App\Services\System\SchedulerHeartbeat::estado());\""
elif echo "$LATIDO" | grep -q '"healthy":false'; then
    echo "   ⚠️  EL CRON NO ESTÁ CORRIENDO. Las tareas automáticas están detenidas."
    echo "   Revisa:  sudo crontab -l -u www-data | grep schedule:run"
    echo "   Debe existir:  * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
else
    echo "   Cron vivo."
fi

echo "✅ Deploy completo"
