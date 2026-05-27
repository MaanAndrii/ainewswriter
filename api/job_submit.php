<?php
/**
 * job_submit.php — приймає POST-запит, створює async-завдання в SQLite,
 * запускає job_worker.php у фоні, повертає {ok:true, job_id:"..."}.
 */

define('MAX_CHARS', 30000);

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

function exec_available(): bool
{
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') js_send(405, ['error' => 'Method not allowed']);

$body = file_get_contents('php://input');
$data = json_decode((string)$body, true);
if (!is_array($data))       js_send(400, ['error' => 'Invalid JSON body']);
if (empty($data['prompt'])) js_send(400, ['error' => 'Missing prompt']);

$prompt    = trim((string)$data['prompt']);
$promptLen = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);
if ($promptLen > MAX_CHARS) {
    js_send(400, ['error' => 'Текст занадто довгий (' . $promptLen . ' символів, ліміт ' . MAX_CHARS . ')']);
}

$source    = (string)($data['source']    ?? '');
$sourceRef = (string)($data['sourceRef'] ?? '');
$extra     = trim((string)($data['extra'] ?? ''));
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
    $db->prepare('DELETE FROM async_job_chunks WHERE job_id IN (SELECT id FROM async_jobs WHERE created_at < ?)')->execute([$cutoff]);
    $db->prepare('DELETE FROM async_jobs WHERE created_at < ?')->execute([$cutoff]);
} catch (Exception $e) {
    error_log('async job cleanup error: ' . $e->getMessage());
}

$jobId = bin2hex(random_bytes(16));

try {
    $db->prepare(
        'INSERT INTO async_jobs (id, created_at, status, model, provider, prompt_text, source_text, source_ref, system_prompt, max_tokens, extra_instructions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        $extra,
    ]);
} catch (Exception $e) {
    error_log('async job insert error: ' . $e->getMessage());
    js_send(500, ['error' => 'Не вдалось створити завдання']);
}

// Знаходимо PHP CLI бінарник (PHP_BINARY у FPM вказує на php-fpm, а не php-cli)
function find_php_cli(): string {
    $candidates = [
        '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2',
        '/usr/bin/php8',   '/usr/bin/php',    '/usr/local/bin/php',
    ];
    foreach ($candidates as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    $which = trim((string)@shell_exec('which php 2>/dev/null'));
    if ($which !== '' && @is_executable($which)) return $which;
    return 'php';
}

if (!exec_available()) {
    $db->prepare('UPDATE async_jobs SET status = ?, finished_at = ? WHERE id = ?')
       ->execute(['failed', date('c'), $jobId]);
    js_send(500, ['error' => 'Фоновий воркер недоступний (exec() вимкнено на сервері)']);
}

$phpBin  = find_php_cli();
$script  = __DIR__ . '/job_worker.php';
$logFile = APP_ROOT . '/storage/worker_errors.log';
$cmd = sprintf(
    'nohup %s %s %s >> %s 2>&1 &',
    escapeshellarg($phpBin),
    escapeshellarg($script),
    escapeshellarg($jobId),
    escapeshellarg($logFile)
);
exec($cmd);

js_send(200, ['ok' => true, 'job_id' => $jobId]);
