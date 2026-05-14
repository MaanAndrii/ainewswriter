<?php
/**
 * index.php — головний роутер ainewswriter
 * Працює як з nginx (try_files $uri /index.php)
 * так і з вбудованим PHP-сервером (php -S localhost:8080)
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
// Відрізаємо query string
$uri = strtok($uri, '?');
// Нормалізуємо: /index.php → /
$uri = preg_replace('~^/index\.php~', '', $uri);
$uri = rtrim($uri, '/') ?: '/';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$base   = __DIR__;

// ── Статичні файли (/public/assets/*) ────────────────────────────────────────
if (preg_match('~^/public/assets/([a-zA-Z0-9._-]+)$~', $uri, $m)) {
    $file = $base . '/public/assets/' . $m[1];
    if (!is_file($file)) { http_response_code(404); echo '404 Not Found'; exit; }
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['css' => 'text/css', 'js' => 'application/javascript',
             'png' => 'image/png', 'jpg' => 'image/jpeg',
             'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
             'woff2' => 'font/woff2', 'woff' => 'font/woff'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    readfile($file);
    exit;
}

// ── API ───────────────────────────────────────────────────────────────────────
if ($uri === '/api/proxy')    { require $base . '/api/proxy.php';        exit; }
if ($uri === '/api/settings') { require $base . '/api/settings_api.php'; exit; }

// ── Адмін ─────────────────────────────────────────────────────────────────────
if ($uri === '/admin')        { require $base . '/admin/admin.php';      exit; }
if ($uri === '/admin/logs')   { require $base . '/admin/log_viewer.php'; exit; }

// ── Головна ───────────────────────────────────────────────────────────────────
if ($uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($base . '/public/newswriter.html');
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>404</title></head>'
   . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
   . '<h2>404 — сторінку не знайдено</h2><p><a href="/">На головну</a></p></body></html>';
