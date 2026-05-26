<?php
/**
 * job_poll.php — короткий GET-ендпоінт для polling async job.
 * GET /api/job_poll?id=<job_id>&after=<last_chunk_id>
 * Повертає {status, chunks:[...], next_after} — живе < 1 секунди.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/app_settings.php';

function jp_send(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jp_send(405, ['error' => 'Method not allowed']);

$body = file_get_contents('php://input');
$data = json_decode((string)$body, true);
if (!is_array($data)) jp_send(400, ['error' => 'Invalid JSON body']);

$jobId = trim((string)($data['id'] ?? ''));
if ($jobId === '' || !preg_match('/^[0-9a-f]{32}$/', $jobId)) {
    jp_send(400, ['error' => 'Invalid job id']);
}

$after = max(0, (int)($data['after'] ?? 0));

$db = get_sqlite_db();
if (!$db) jp_send(500, ['error' => 'DB недоступна']);

// Статус завдання
$sStmt = $db->prepare('SELECT status FROM async_jobs WHERE id = ?');
$sStmt->execute([$jobId]);
$sRow   = $sStmt->fetch(PDO::FETCH_ASSOC);
$status = (string)($sRow['status'] ?? 'unknown');

if ($status === 'unknown') jp_send(404, ['error' => 'Job not found']);

// Нові чанки
$cStmt = $db->prepare(
    'SELECT id, chunk FROM async_job_chunks WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 2000'
);
$cStmt->execute([$jobId, $after]);
$rows = $cStmt->fetchAll(PDO::FETCH_ASSOC);

$chunks  = array_column($rows, 'chunk');
$nextId  = empty($rows) ? $after : (int)end($rows)['id'];

jp_send(200, ['status' => $status, 'chunks' => $chunks, 'next_after' => $nextId]);
