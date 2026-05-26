<?php
/**
 * job_submit.php — приймає POST-запит, створює async-завдання в SQLite,
 * запускає job_worker.php у фоні, повертає {ok:true, job_id:"..."}.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/app_settings.php';

function js_send(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') js_send(405, ['error' => 'Method not allowed']);

$body = file_get_contents('php://input');
$data = json_decode((string)$body, true);
if (!is_array($data))       js_send(400, ['error' => 'Invalid JSON body']);
if (empty($data['prompt'])) js_send(400, ['error' => 'Missing prompt']);

$prompt    = trim((string)$data['prompt']);
$source    = (string)($data['source']    ?? '');
$sourceRef = (string)($data['sourceRef'] ?? '');
$model     = (string)($data['model']     ?? '');
$sysOver   = trim((string)($data['systemPromptOverride'] ?? ''));

$settings  = load_settings();
$modelsMap = settings_model_map($settings);
$modelMeta = $modelsMap[$model] ?? null;
if (!$modelMeta) js_send(500, ['error' => 'Немає доступних моделей']);

$systemPrompt = resolve_system_prompt($settings);
if ($sysOver !== '') $systemPrompt = $sysOver;
if ($systemPrompt === '') $systemPrompt = get_default_system_prompt();

$maxTokens = (int)($modelMeta['max_tokens'] ?? 8000);
if ($maxTokens < 256)   $maxTokens = 256;
if ($maxTokens > 32000) $maxTokens = 32000;

$db = get_sqlite_db();
if (!$db) js_send(500, ['error' => 'DB недоступна']);

// Cleanup jobs older than 2 hours
try {
    $cutoff = date('c', time() - 7200);
    $oldIds = $db->prepare('SELECT id FROM async_jobs WHERE created_at < ?');
    $oldIds->execute([$cutoff]);
    foreach ($oldIds->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
        $db->prepare('DELETE FROM async_job_chunks WHERE job_id = ?')->execute([$oldId]);
        $db->prepare('DELETE FROM async_jobs WHERE id = ?')->execute([$oldId]);
    }
} catch (Exception $e) {
    error_log('async job cleanup error: ' . $e->getMessage());
}

$jobId = bin2hex(random_bytes(16));

try {
    $db->prepare(
        'INSERT INTO async_jobs (id, created_at, status, model, provider, prompt_text, source_text, source_ref, system_prompt, max_tokens)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $jobId,
        date('c'),
        'pending',
        $model,
        (string)$modelMeta['provider'],
        $prompt,
        $source,
        $sourceRef,
        $systemPrompt,
        $maxTokens,
    ]);
} catch (Exception $e) {
    error_log('async job insert error: ' . $e->getMessage());
    js_send(500, ['error' => 'Не вдалось створити завдання']);
}

// Spawn background worker
$phpBin  = PHP_BINARY;
$script  = realpath(__DIR__ . '/job_worker.php');
$logFile = APP_ROOT . '/storage/worker_errors.log';
$cmd = sprintf(
    '%s %s %s >> %s 2>&1 &',
    escapeshellarg($phpBin),
    escapeshellarg($script),
    escapeshellarg($jobId),
    escapeshellarg($logFile)
);
exec($cmd);

js_send(200, ['ok' => true, 'job_id' => $jobId]);
