# Deploy VPS — Guía paso a paso

> Receta de cocina para Ubuntu 24.04. El dev no tiene experiencia VPS — todos los comandos son copy-paste exactos.
> Verificado en producción: `sender.prestamaz.site` (Ubuntu 24.04.4 LTS, junio 2026).

---

## 1. Acceso al servidor

El servidor de producción es una VM con acceso SSH:

```bash
ssh adminsender@prestamazcln.dyndns.biz
```

Para un VPS propio (Hetzner CX22 ~$5/mes), crear con Ubuntu 24.04 LTS y cargar llave SSH pública al crearlo.

---

## 2. Generar llave SSH (si no tienes)

En tu PC local (Windows PowerShell):

```bash
ssh-keygen -t ed25519 -C "wa-cloud-panel"
# Presiona Enter a todo (sin passphrase)
cat ~/.ssh/id_ed25519.pub
# Copia ese output → lo mandas al admin del server para que lo agregue
```

---

## 3. Actualizar paquetes

```bash
sudo apt update && sudo apt upgrade -y
```

---

## 4. Agregar repositorio PHP y instalar stack

En Ubuntu 24.04 PHP 8.2 no está en los repos por defecto — agregar primero:

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
```

Luego instalar todo:

```bash
sudo apt install -y \
  nginx \
  php8.2 php8.2-fpm php8.2-mysql php8.2-xml php8.2-curl \
  php8.2-mbstring php8.2-zip php8.2-bcmath php8.2-tokenizer \
  php8.2-cli php8.2-gd \
  mysql-server \
  supervisor \
  git \
  unzip \
  curl
```

> `php8.2-gd` es obligatorio — lo requiere phpspreadsheet para exports Excel.

---

## 5. Instalar Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version  # debe mostrar versión 2.x
```

---

## 6. Instalar Node.js 20

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node --version  # v20.x
npm --version   # 10.x
```

---

## 7. Configurar MySQL

```bash
sudo mysql
```

```sql
CREATE DATABASE wa_cloud_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wa_user'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';
GRANT ALL PRIVILEGES ON wa_cloud_panel.* TO 'wa_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 8. Clonar el repositorio

```bash
cd /var/www
sudo git clone https://TU_GITHUB_TOKEN@github.com/alexis-gd/wa-cloud-panel.git
sudo chown -R adminsender:www-data wa-cloud-panel
cd wa-cloud-panel
```

---

## 9. Instalar dependencias PHP y compilar frontend

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

---

## 10. Configurar .env

```bash
cp .env.example .env
nano .env
php artisan key:generate
```

Variables críticas:

```env
APP_URL=https://sender.prestamaz.site   # ⚠️ CORS usa este valor — URL exacta del panel
APP_ENV=production
APP_DEBUG=false

DB_DATABASE=wa_cloud_panel
DB_USERNAME=wa_user
DB_PASSWORD=tu_password_aqui

WA_TOKEN=...                            # Token Meta (referencia — los envíos leen de BD)
WA_PHONE_ID=1082360764952377
WA_WABA_ID=1236630511398211
WA_WEBHOOK_VERIFY_TOKEN=...             # Secreto separado del token Meta
WA_APP_SECRET=...                       # Para validar firma X-Hub-Signature-256

QUEUE_CONNECTION=database
```

> **Nota CORS**: `APP_URL` determina qué origen puede hacer peticiones al API. Si el dominio no coincide exactamente (http vs https, con www vs sin www), el navegador bloqueará las peticiones del frontend.

---

## 11. Migraciones y permisos

```bash
php artisan migrate --force
php artisan storage:link
sudo chown -R adminsender:www-data /var/www/wa-cloud-panel
sudo chown -R www-data:www-data /var/www/wa-cloud-panel/storage
sudo chown -R www-data:www-data /var/www/wa-cloud-panel/bootstrap/cache
sudo chmod -R 775 /var/www/wa-cloud-panel/storage
sudo chmod -R 775 /var/www/wa-cloud-panel/bootstrap/cache
```

---

## 12. Crear usuario admin inicial

```bash
php artisan tinker
```

```php
User::create(['name' => 'Admin', 'email' => 'admin@prestamas.mx', 'password' => bcrypt('admin1234'), 'role' => 'superadmin', 'is_active' => true]);
```

---

## 13. Insertar número de WhatsApp

```bash
php artisan tinker
```

```php
\App\Models\PhoneNumber::create([
    'display_name'    => 'Sandbox Prestamaz',
    'phone_number_id' => '1082360764952377',
    'waba_id'         => '1236630511398211',
    'token'           => env('WA_TOKEN'),
    'daily_limit'     => 250,
    'is_active'       => true,
]);
```

> Si el token aparece como expirado en `/api/phone-health`, generar nuevo System User Token en business.facebook.com → Usuarios del sistema → `waclouddev` → Generar token, y actualizarlo vía tinker:
> ```php
> \App\Models\PhoneNumber::where('phone_number_id', '1082360764952377')->update(['token' => 'TOKEN_NUEVO']);
> ```

---

## 14. Configurar Nginx

```bash
sudo nano /etc/nginx/sites-available/wa-cloud-panel
```

```nginx
server {
    listen 80;
    server_name sender.prestamaz.site;
    root /var/www/wa-cloud-panel/public;

    index index.php;

    # Subidas: imagen de plantilla (tope de Meta 5 MB) y Excel de contactos.
    # El default de nginx es 1M: con eso, un banner normal ya falla con 413 antes de
    # llegar a Laravel, y la respuesta ni siquiera es JSON.
    client_max_body_size 8M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/wa-cloud-panel /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Límites de subida en PHP (obligatorio)

Los defaults de PHP-FPM (`upload_max_filesize=2M`) son **más bajos** que el tope de 5 MB que
acepta Meta para la imagen de una plantilla. Sin subirlos, PHP descarta el archivo antes de que
Laravel lo vea.

```bash
# Ver los valores actuales
php -i | grep -E "upload_max_filesize|post_max_size"

# Subirlos (ajusta la versión de PHP si no es 8.2)
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 8M/' /etc/php/8.2/fpm/php.ini
sudo sed -i 's/^post_max_size = .*/post_max_size = 8M/'             /etc/php/8.2/fpm/php.ini
sudo systemctl restart php8.2-fpm

# Verificar (php -i lee el ini de CLI, que es otro archivo; este mira el de FPM)
php -c /etc/php/8.2/fpm/php.ini -i | grep -E "upload_max_filesize|post_max_size"
```

Se dejan en **8M**, un poco arriba del tope de Meta, para que quien rechace el archivo sea la
validación del panel (mensaje claro en español) y no nginx con un 413 en HTML.

> SSL lo maneja el proxy inverso de Joseph (Nginx edge) — no se necesita Certbot en esta VM.

---

## 15. Configurar Supervisor (queue worker)

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/wa-queue.conf
```

```ini
[program:wa-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wa-cloud-panel/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/wa-cloud-panel/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wa-queue:*
sudo supervisorctl status  # debe mostrar RUNNING
```

---

## 16. Cron para scheduler Laravel

```bash
sudo crontab -e -u www-data
```

Agregar:

```
* * * * * cd /var/www/wa-cloud-panel && php artisan schedule:run >> /dev/null 2>&1
```

---

## 17. Health check final

```bash
curl https://sender.prestamaz.site/api/health
# Debe responder: {"status":"ok","db":"ok"}
```

---

## Actualizar código (deploys futuros)

```bash
cd /var/www/wa-cloud-panel
sudo git pull
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
sudo supervisorctl restart wa-queue:*
```

> ⚠️ **`php artisan migrate --force` siempre ANTES de reiniciar el queue.** Si el queue arranca con código nuevo pero la BD no tiene las tablas nuevas, los jobs explotan silenciosamente. El orden importa.
>
> Migraciones pendientes de delivery-feedback (v0.6.0):
> - `add_delivery_error_to_message_log` — columnas `delivery_error_code` y `delivery_error_title` en `message_log`
> - `create_app_notifications_table` — tabla `app_notifications` para el badge de campana

---

## Troubleshooting

**500 — Vite manifest not found:**
```bash
npm install && npm run build
```

**500 — Ver error real:**
```bash
sudo grep "production.ERROR" storage/logs/laravel.log | tail -5
```

**502 Bad Gateway:**
```bash
sudo systemctl status php8.2-fpm
sudo tail -f /var/log/nginx/error.log
```

**Permission denied en storage:**
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan cache:clear
```

**Queue no arranca:**
```bash
sudo supervisorctl status
sudo tail -f storage/logs/worker.log
sudo supervisorctl restart wa-queue:*
```

**Migraciones fallan:**
```bash
php artisan migrate:status
# Verificar credenciales en .env
# Verificar que el usuario MySQL tiene permisos
```

**Token Meta expirado:**
```bash
# Generar nuevo en business.facebook.com → Usuarios del sistema → waclouddev → Generar token
php artisan tinker
\App\Models\PhoneNumber::where('phone_number_id', '1082360764952377')->update(['token' => 'TOKEN_NUEVO']);
```
