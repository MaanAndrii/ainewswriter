# Розгортання проєкту на Raspberry Pi через Git (локальна мережа)

Це покрокова інструкція **українською**: як клонувати проєкт з Git, налаштувати nginx + php-fpm, запустити і надалі оновлювати.

---

## 0) Що потрібно

- Raspberry Pi OS (Debian-based)
- Доступ по SSH до Pi
- Git-репозиторій з вашим проєктом (GitHub/GitLab/самостійний Git-сервер)
- Домен не потрібен (працюємо в LAN по IP)

У прикладах:
- Користувач: `pi`
- Шлях: `/var/www/ainewswriter`
- URL репозиторію: `https://github.com/<user>/<repo>.git`

---

## 1) Встановити пакети

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-curl git
```

Перевірка сервісів:

```bash
systemctl is-active nginx
systemctl is-active php8.4-fpm || systemctl is-active php8.3-fpm || systemctl is-active php-fpm
```

---

## 2) Підготувати папку для проєкту

```bash
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www
cd /var/www
```

---

## 3) Клонувати проєкт з Git

### Варіант A: HTTPS

```bash
git clone https://github.com/<user>/<repo>.git ainewswriter
cd /var/www/ainewswriter
```

### Варіант B: SSH

1. Створити SSH-ключ на Pi (якщо ще немає):

```bash
ssh-keygen -t ed25519 -C "raspberry-pi"
cat ~/.ssh/id_ed25519.pub
```

2. Додати виведений публічний ключ в GitHub/GitLab (SSH keys).
3. Клонувати:

```bash
git clone git@github.com:<user>/<repo>.git ainewswriter
cd /var/www/ainewswriter
```

---

## 4) Права доступу

```bash
sudo chown -R www-data:www-data /var/www/ainewswriter
sudo find /var/www/ainewswriter -type d -exec chmod 755 {} \;
sudo find /var/www/ainewswriter -type f -exec chmod 644 {} \;
```

---

## 5) Налаштувати nginx

Створіть/оновіть конфіг:

```bash
sudo nano /etc/nginx/sites-available/ainewswriter
```

Вставте:

```nginx
server {
    listen 80 default_server;
    server_name _;

    root /var/www/ainewswriter;
    index index.php;

    # Статичні файли — роздаємо напряму (без PHP)
    location /public/assets/ {
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public";
    }

    # Всі інші запити йдуть через роутер index.php
    location / {
        try_files $uri /index.php;
    }

    # PHP через php-fpm
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    # Блокуємо прямий доступ до службових файлів
    location ~* \.(env|log|md)$ {
        deny all;
    }
}
```

> Якщо у вас не PHP 8.4, змініть сокет на актуальний (наприклад `php8.3-fpm.sock`).

> `fastcgi_read_timeout 300` — потрібен для AI-моделей з довгим часом відповіді (reasoning-моделі можуть думати 60–180 секунд). Без цього nginx поверне `504 Gateway Timeout` ще до того як модель відповість.

Увімкніть сайт:

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/ainewswriter /etc/nginx/sites-enabled/ainewswriter
sudo nginx -t
sudo systemctl restart nginx
```

### Таймаут PHP-FPM

Відкрийте конфіг пулу:

```bash
sudo nano /etc/php/8.4/fpm/pool.d/www.conf
```

Знайдіть і встановіть (або додайте після `pm.max_requests`):

```ini
request_terminate_timeout = 300
```

Перезапустіть PHP-FPM:

```bash
sudo systemctl restart php8.4-fpm
```

## 5a) OPCache та стиснення

### OPCache

Скопіюйте файл `opcache.ini` з репозиторію у конфігурацію PHP:

```bash
sudo cp /var/www/ainewswriter/opcache.ini /etc/php/8.4/fpm/conf.d/99-ainewswriter.ini
sudo systemctl restart php8.4-fpm || sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm
```

Перевірка, що OPCache активний:

```bash
php -r "echo opcache_get_status() ? 'OPCache OK' : 'OPCache вимкнено';"
```

### Gzip у nginx

Додайте до конфігу nginx (всередині блоку `server {}`), після рядку `listen 80 default_server;`:

```nginx
gzip on;
gzip_types text/html text/css application/javascript application/json;
gzip_min_length 1024;
```

Після змін перевірте і перезапустіть nginx:

```bash
sudo nginx -t
sudo systemctl restart nginx
```

## 6) Налаштувати локальні змінні (.env.local)

```bash
sudo bash -c 'cat > /var/www/ainewswriter/.env.local <<EOF
ADMIN_PASSWORD=change-me-now
ANTHROPIC_API_KEY=
XAI_API_KEY=
EOF'

sudo chown www-data:www-data /var/www/ainewswriter/.env.local
sudo chmod 600 /var/www/ainewswriter/.env.local
```

За потреби можна задати інший шлях до env-файлу через змінну середовища `APP_ENV_FILE` у php-fpm service override.

---

## 7) Запуск

```bash
sudo systemctl restart php8.4-fpm || sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm
sudo systemctl restart nginx
```

Відкрийте в браузері:

- `http://<IP_RASPBERRY>/` → автоматично перейде на редактор новин
- `http://<IP_RASPBERRY>/admin/admin.php`
- `http://<IP_RASPBERRY>/admin/log_viewer.php`

---

## 8) Як оновлювати проєкт з Git

Перейдіть у папку проєкту:

```bash
cd /var/www/ainewswriter
```

### Звичайне оновлення (same branch)

```bash
sudo -u www-data git fetch --all
sudo -u www-data git pull
sudo systemctl restart php8.4-fpm || sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm
sudo systemctl restart nginx
```

### Оновлення на конкретну гілку

```bash
sudo -u www-data git fetch --all
sudo -u www-data git checkout <branch_name>
sudo -u www-data git pull
sudo systemctl restart php8.4-fpm || sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm
sudo systemctl restart nginx
```

---

## 9) Діагностика, якщо щось не працює

### Логи nginx і php-fpm

```bash
sudo tail -n 150 /var/log/nginx/error.log
sudo journalctl -u php8.4-fpm -n 150 --no-pager || sudo journalctl -u php8.3-fpm -n 150 --no-pager || sudo journalctl -u php-fpm -n 150 --no-pager
```

### Перевірка синтаксису PHP

```bash
cd /var/www/ainewswriter
find . -maxdepth 3 -name "*.php" -print0 | xargs -0 -n1 php -l
```

### Перевірка конфігу nginx

```bash
sudo nginx -t
```

---

## 10) Рекомендований мінімальний порядок деплою

1. `git pull`
2. `php -l` для всіх PHP-файлів
3. `nginx -t`
4. перезапуск `php-fpm` + `nginx`
5. відкриття `http://<IP>/` — має редиректити на редактор
6. відкриття `/admin/admin.php` і перевірка статистики
