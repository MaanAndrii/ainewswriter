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
if (preg_match('~^/public/assets/([a-zA-Z0-9._/-]+)$~', $uri, $m)) {
    $file = $base . '/public/assets/' . $m[1];
    if (!is_file($file)) { http_response_code(404); echo '404 Not Found'; exit; }
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['css' => 'text/css', 'js' => 'application/javascript',
             'png' => 'image/png', 'jpg' => 'image/jpeg',
             'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
             'ttf' => 'font/ttf', 'woff2' => 'font/woff2', 'woff' => 'font/woff'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    readfile($file);
    exit;
}

// ── PWA ───────────────────────────────────────────────────────────────────────
if ($uri === '/manifest.json') {
    $file = $base . '/public/manifest.json';
    if (!is_file($file)) { http_response_code(404); echo '404 Not Found'; exit; }
    header('Content-Type: application/manifest+json');
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}
if ($uri === '/sw.js') {
    $file = $base . '/public/sw.js';
    if (!is_file($file)) { http_response_code(404); echo '404 Not Found'; exit; }
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    readfile($file);
    exit;
}

// ── API ───────────────────────────────────────────────────────────────────────
if ($uri === '/api/settings')   { require $base . '/api/settings_api.php'; exit; }
if ($uri === '/api/job_submit') { require $base . '/api/job_submit.php';   exit; }
if ($uri === '/api/job_poll')   { require $base . '/api/job_poll.php';     exit; }

// ── Адмін ─────────────────────────────────────────────────────────────────────
if ($uri === '/admin')        { require $base . '/admin/admin.php'; exit; }
if ($uri === '/admin/logs')   { header('Location: /admin/admin.php'); exit; }

// ── Головна ───────────────────────────────────────────────────────────────────
if ($uri === '/') {
    require_once $base . '/version.php';
    $v    = APP_VERSION;
    $html = (string)file_get_contents($base . '/public/newswriter.html');
    $html = str_replace('/public/assets/newswriter.css"', '/public/assets/newswriter.css?v=' . $v . '"', $html);
    $html = str_replace('/public/assets/newswriter.js"',  '/public/assets/newswriter.js?v='  . $v . '"', $html);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>404</title></head>'
   . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
   . '<h2>404 — сторінку не знайдено</h2><p><a href="/">На головну</a></p></body></html>';
