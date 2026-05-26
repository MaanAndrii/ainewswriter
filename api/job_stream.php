<?php
/**
 * job_stream.php — SSE-стрим для async job queue.
 * GET /api/job_stream?id=<job_id>
 *
 * Опитує async_job_chunks кожні 300 мс, пересилає рядки браузеру.
 * Завершується коли job отримує статус done/failed і всі чанки доставлені.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/app_settings.php';

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$jobId = trim((string)($_GET['id'] ?? ''));
if ($jobId === '' || !preg_match('/^[0-9a-f]{32}$/', $jobId)) {
    http_response_code(400);
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['error' => 'Невірний job id'], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}

$db = get_sqlite_db();
if (!$db) {
    http_response_code(500);
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['error' => 'DB недоступна'], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}

if (ob_get_level()) ob_end_clean();
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$cursor      = 0;
$deadline    = time() + 300; // 5-хвилинний максимум
$lastPing    = time();
$pollUs      = 300000; // 300 мс
$noChunkWait = 0; // скільки секунд чекаємо без нових чанків після завершення

while (time() < $deadline) {
    if (connection_aborted()) break;

    // Keepalive кожні 20 секунд (SSE-comment, браузер ігнорує)
    if (time() - $lastPing >= 20) {
        echo ": ping\n\n";
        flush();
        $lastPing = time();
    }

    // Отримати нові чанки
    $stmt = $db->prepare(
        'SELECT id, chunk FROM async_job_chunks WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 200'
    );
    $stmt->execute([$jobId, $cursor]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cursor = (int)$row['id'];
        echo $row['chunk'] . "\n\n";
        flush();
        if (connection_aborted()) break 2;
    }

    // Перевірити статус завдання
    $sStmt = $db->prepare('SELECT status FROM async_jobs WHERE id = ?');
    $sStmt->execute([$jobId]);
    $sRow   = $sStmt->fetch(PDO::FETCH_ASSOC);
    $status = (string)($sRow['status'] ?? 'unknown');

    if (in_array($status, ['done', 'failed'], true)) {
        if (empty($rows)) {
            // Всі чанки доставлені, завдання завершене
            break;
        }
        // Ще є чанки — зробимо ще один прохід без затримки
        continue;
    }

    if (empty($rows)) {
        usleep($pollUs);
    }
}
