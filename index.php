<?php
/**
 * index.php — головний роутер проєкту ainewswriter
 *
 * nginx передає сюди всі запити через:
 *   location / { try_files $uri /index.php; }
 *
 * Роутер сам вирішує що повернути залежно від $uri.
 */

$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$uri = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$base = __DIR__;

// ── 1. Статичні файли (CSS, JS, favicon тощо) ──────────────────────────────
// nginx має роздавати їх напряму, але якщо запит все ж дійшов сюди — роздаємо
if (preg_match('~^/public/assets/(.+)$~', $uri, $m)) {
    $file = $base . '/public/assets/' . $m[1];
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'ico'  => 'image/x-icon',
            'svg'  => 'image/svg+xml',
            'woff2'=> 'font/woff2',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
    http_response_code(404); echo '404 Not Found'; exit;
}

// ── 2. API ──────────────────────────────────────────────────────────────────
if ($uri === '/api/proxy') {
    require $base . '/api/proxy.php'; exit;
}
if ($uri === '/api/settings') {
    require $base . '/api/settings_api.php'; exit;
}

// ── 3. Адмін-панель ─────────────────────────────────────────────────────────
if ($uri === '/admin') {
    require $base . '/admin/admin.php'; exit;
}
if ($uri === '/admin/logs') {
    require $base . '/admin/log_viewer.php'; exit;
}

// ── 4. Головна сторінка ─────────────────────────────────────────────────────
if ($uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($base . '/public/newswriter.html');
    exit;
}

// ── 5. 404 ──────────────────────────────────────────────────────────────────
http_response_code(404);
echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>404</title></head>'
   . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
   . '<h2>404 — сторінку не знайдено</h2>'
   . '<p><a href="/">На головну</a></p></body></html>';
