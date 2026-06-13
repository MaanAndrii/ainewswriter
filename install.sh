#!/usr/bin/env bash
# install.sh — розгортання ainewswriter на Debian/Raspberry Pi OS
# Запускати з кореня репозиторію: sudo bash install.sh

set -euo pipefail

# ── Кольори ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "${GREEN}✔${NC}  $*"; }
info() { echo -e "${BLUE}▸${NC}  $*"; }
warn() { echo -e "${YELLOW}⚠${NC}  $*"; }
die()  { echo -e "${RED}✘${NC}  $*" >&2; exit 1; }
step() { echo -e "\n${BOLD}$*${NC}"; }

# ── Перевірки ─────────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Запустіть з sudo: sudo bash install.sh"
[[ -f index.php && -f core/app_settings.php ]] \
    || die "Запускайте з кореня репозиторію ainewswriter"

APP_DIR="$(pwd)"
WEB_USER="www-data"

# ── Крок 0: git (потрібен для подальших git pull) ──────────────────────────────
if ! command -v git &>/dev/null; then
    info "Встановлення git..."
    apt-get install -y -qq git
    ok "git встановлено"
fi

step "1/7  Встановлення пакетів"
apt-get update -qq
PACKAGES=(nginx php-fpm php-cli php-curl php-mbstring php-sqlite3)
apt-get install -y -qq "${PACKAGES[@]}"
ok "Пакети встановлено"

# ── Визначення версії PHP ──────────────────────────────────────────────────────
PHP_BIN=$(command -v php8.4 || command -v php8.3 || command -v php8.2 || command -v php8.1 || command -v php || true)
[[ -n "$PHP_BIN" ]] || die "PHP не знайдено після встановлення"
PHP_VER=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
FPM_SERVICE="php${PHP_VER}-fpm"
FPM_POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
FPM_CONF_DIR="/etc/php/${PHP_VER}/fpm/conf.d"
ok "PHP ${PHP_VER} (сокет: ${FPM_SOCK})"

step "2/7  Права доступу"
chown -R "${WEB_USER}:${WEB_USER}" "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 755 {} \;
find "${APP_DIR}" -type f -exec chmod 644 {} \;
chmod 755 "${APP_DIR}/install.sh"
mkdir -p "${APP_DIR}/storage"
chown "${WEB_USER}:${WEB_USER}" "${APP_DIR}/storage"
ok "Права встановлено"

step "3/7  Конфігурація nginx"
NGINX_CONF="/etc/nginx/sites-available/ainewswriter"
cat > "${NGINX_CONF}" <<NGINX
server {
    listen 80 default_server;
    server_name _;

    root ${APP_DIR};
    index index.php;

    location /public/assets/ {
        try_files \$uri =404;
        expires 7d;
        add_header Cache-Control "public";
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /index.php {
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${APP_DIR}/index.php;
        fastcgi_param DOCUMENT_ROOT   ${APP_DIR};
    }

    location ~ \\.php\$ {
        return 404;
    }

    location ~* \\.(env|log|md|sh)\$ {
        deny all;
    }

    gzip on;
    gzip_types text/html text/css application/javascript application/json;
    gzip_min_length 1024;
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/ainewswriter
nginx -t -q
ok "nginx налаштовано"

step "4/7  Таймаут PHP-FPM"
if [[ -f "${FPM_POOL}" ]]; then
    if grep -q "^request_terminate_timeout" "${FPM_POOL}"; then
        sed -i 's/^request_terminate_timeout.*/request_terminate_timeout = 300/' "${FPM_POOL}"
    else
        echo "request_terminate_timeout = 300" >> "${FPM_POOL}"
    fi
    ok "request_terminate_timeout = 300"
else
    warn "Файл ${FPM_POOL} не знайдено — таймаут не виставлено"
fi

step "5/7  OPCache"
if [[ -f "${APP_DIR}/opcache.ini" && -d "${FPM_CONF_DIR}" ]]; then
    sed "s|/etc/php/8\.[0-9]|/etc/php/${PHP_VER}|g" \
        "${APP_DIR}/opcache.ini" > "${FPM_CONF_DIR}/99-ainewswriter.ini"
    ok "OPCache конфіг скопійовано у ${FPM_CONF_DIR}/99-ainewswriter.ini"
else
    warn "opcache.ini або ${FPM_CONF_DIR} не знайдено — OPCache пропущено"
fi

step "6/7  Перезапуск сервісів"
systemctl restart "${FPM_SERVICE}"
systemctl restart nginx
ok "${FPM_SERVICE} перезапущено"
ok "nginx перезапущено"

# ── Перевірка сокета FPM ───────────────────────────────────────────────────────
SOCK_WAIT=0
while [[ ! -S "${FPM_SOCK}" && $SOCK_WAIT -lt 10 ]]; do
    sleep 1; SOCK_WAIT=$((SOCK_WAIT + 1))
done
if [[ -S "${FPM_SOCK}" ]]; then
    ok "PHP-FPM сокет готовий: ${FPM_SOCK}"
else
    warn "Сокет ${FPM_SOCK} не з'явився — перевірте: sudo journalctl -u ${FPM_SERVICE} -n 30"
fi

step "7/7  Перевірка синтаксису PHP"
ERRORS=0
while IFS= read -r -d '' file; do
    if ! "$PHP_BIN" -l "$file" &>/dev/null; then
        warn "Синтаксична помилка: $file"
        ERRORS=$((ERRORS + 1))
    fi
done < <(find "${APP_DIR}" -maxdepth 4 -name "*.php" \
    -not -path "*/.git/*" -not -path "*/.claude/*" -print0)

if [[ $ERRORS -eq 0 ]]; then
    ok "Синтаксис усіх PHP-файлів коректний"
else
    warn "${ERRORS} файл(ів) з помилками — перевірте вище"
fi

# ── Самоперевірка HTTP ────────────────────────────────────────────────────────
info "Перевірка HTTP..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://127.0.0.1/ 2>/dev/null || echo "000")
if [[ "$HTTP_CODE" == "200" ]]; then
    ok "HTTP 200 — сервер відповідає коректно"
elif [[ "$HTTP_CODE" == "000" ]]; then
    warn "curl не зміг підключитись — перевірте: sudo systemctl status nginx"
else
    warn "HTTP ${HTTP_CODE} — можлива проблема. Логи: sudo tail -n 30 /var/log/nginx/error.log"
fi

# ── Визначення IP ─────────────────────────────────────────────────────────────
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')

echo
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║        Розгортання завершено ✔           ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════╝${NC}"
echo
echo -e "  Редактор:   ${BOLD}http://${LOCAL_IP}/${NC}"
echo -e "  Адмін:      ${BOLD}http://${LOCAL_IP}/admin${NC}"
echo
echo -e "  ${YELLOW}${BOLD}Перший вхід: пароль  change-me-now${NC}"
echo -e "  ${YELLOW}Одразу змініть пароль і введіть API-ключі в адмін-панелі.${NC}"
echo
