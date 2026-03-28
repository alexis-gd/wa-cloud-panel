# Deploy VPS — Guía paso a paso

> Receta de cocina para Ubuntu 22.04. El dev no tiene experiencia VPS — todos los comandos son copy-paste exactos.

---

## 1. Contratar VPS

- Proveedor: **Hetzner** (hetzner.com)
- Servidor: **CX22** (~$5/mes) — 2 vCPU, 4GB RAM, 40GB SSD
- Sistema operativo: **Ubuntu 22.04 LTS**
- Datacenter: **Ashburn, VA** (latencia baja desde México)
- Crear cuenta, agregar método de pago, lanzar servidor
- Al crear: cargar tu llave SSH pública (ver paso 2)

---

## 2. Generar llave SSH (si no tienes)

En tu PC local (Windows, en Git Bash o WSL):

```bash
ssh-keygen -t ed25519 -C "wa-cloud-panel"
# Presiona Enter a todo (sin passphrase para automatización)
cat ~/.ssh/id_ed25519.pub
# Copia ese output → lo pegas en Hetzner al crear el servidor
```

---

## 3. Primer acceso y usuario no-root

```bash
# Conectar como root (sustituye la IP de tu VPS)
ssh root@TU_IP_VPS

# Crear usuario de deploy
adduser deploy
usermod -aG sudo deploy

# Copiar llaves SSH al nuevo usuario
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Deshabilitar login con contraseña (más seguro)
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd

# Salir y reconectar como deploy
exit
ssh deploy@TU_IP_VPS
```

---

## 4. Instalar el stack completo

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y \
  nginx \
  php8.1-fpm php8.1-mysql php8.1-xml php8.1-curl php8.1-mbstring php8.1-zip \
  mysql-server \
  redis-server \
  supervisor \
  git \
  unzip \
  curl
```

---

## 5. Instalar Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version  # debe mostrar versión 2.x
```

---

## 6. Configurar MySQL

```bash
sudo mysql_secure_installation
# Responder: Y, Y, Y, Y, Y (todas las opciones de seguridad)

sudo mysql -u root -p
```

```sql
CREATE DATABASE wa_cloud_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wa_user'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';
GRANT ALL PRIVILEGES ON wa_cloud_panel.* TO 'wa_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 7. Deploy del código

```bash
cd /var/www
sudo git clone https://github.com/TU_USUARIO/wa-cloud-panel.git
sudo chown -R deploy:www-data wa-cloud-panel
cd wa-cloud-panel

composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env  # configurar DB, WA tokens, APP_KEY
php artisan key:generate
php artisan migrate --force
```

---

## 8. Configurar Nginx

```bash
sudo nano /etc/nginx/sites-available/wa-cloud-panel
```

Pegar este contenido (sustituir `tudominio.com`):

```nginx
server {
    listen 80;
    server_name tudominio.com www.tudominio.com;
    root /var/www/wa-cloud-panel/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
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
sudo nginx -t  # debe decir "syntax is ok"
sudo systemctl reload nginx
```

---

## 9. Permisos de carpetas

```bash
sudo chown -R www-data:www-data /var/www/wa-cloud-panel/storage
sudo chown -R www-data:www-data /var/www/wa-cloud-panel/bootstrap/cache
sudo chmod -R 775 /var/www/wa-cloud-panel/storage
sudo chmod -R 775 /var/www/wa-cloud-panel/bootstrap/cache
```

---

## 10. SSL con Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d tudominio.com -d www.tudominio.com
# Seguir instrucciones, aceptar términos, ingresar email
# Certbot configura Nginx automáticamente para HTTPS
```

---

## 11. Configurar Supervisor (queue worker)

```bash
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

## 12. Cron para scheduler Laravel

```bash
sudo crontab -e -u www-data
```

Agregar esta línea:

```
* * * * * cd /var/www/wa-cloud-panel && php artisan schedule:run >> /dev/null 2>&1
```

---

## 13. Redis en .env (Stage 2)

En `.env`:
```
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Verificar:
```bash
redis-cli ping  # debe responder PONG
```

---

## 14. Firewall

```bash
sudo ufw allow 22    # SSH
sudo ufw allow 80    # HTTP
sudo ufw allow 443   # HTTPS
sudo ufw enable
sudo ufw status
```

---

## 15. Health check final

```bash
curl https://tudominio.com/api/health
# Debe responder: {"status":"ok","db":"ok"}
```

---

## Troubleshooting

**502 Bad Gateway:**
```bash
sudo systemctl status php8.1-fpm
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
