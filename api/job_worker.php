<?php
/**
 * job_worker.php — фоновий CLI-воркер для async job queue.
 * Запускається через job_submit.php: php job_worker.php <job_id>
 *
 * Читає завдання з async_jobs, викликає LLM API (streaming),
 * зберігає SSE-чанки в async_job_chunks, логує в requests/generations.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

define('TIMEOUT', 120);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/app_settings.php';
require_once __DIR__ . '/providers/BaseProvider.php';
require_once __DIR__ . '/providers/AnthropicProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/OpenAICompatProvider.php';

// ── Утиліти ──────────────────────────────────────────────────────────────────

function w_strlen(string $v): int
{
    return function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
}

function w_extract_json(string $text): ?array
{
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($parsed) && count($parsed) > 0 ? $parsed : null;
}

function w_valid_json(string $text): bool
{
    return w_extract_json($text) !== null;
}

function w_retry_prompt(string $original): string
{
    return $original
        . "\n\nКРИТИЧНО: попередня відповідь не містила валідного JSON-об'єкта. "
        . "Поверни ВИКЛЮЧНО валідний JSON-об'єкт (починай з {, закінчуй }), без будь-якого іншого тексту.";
}

function w_calc_cost(array $usage, array $modelMeta): float
{
    $inp = (int)($usage['input_tokens']  ?? 0);
    $out = (int)($usage['output_tokens'] ?? 0);
    return ($inp * (float)($modelMeta['inp'] ?? 0) + $out * (float)($modelMeta['out'] ?? 0)) / 1_000_000;
}

function w_non_stream(string $url, array $headers, string $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => TIMEOUT,
    ]);
    $resp    = (string)curl_exec($ch);
    $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return ['response' => $resp, 'code' => $code, 'curl_error' => $curlErr];
}

function w_make_provider(string $provider, array $keys, bool $useWebSearch): BaseProvider
{
    return match (true) {
        $provider === 'anthropic'                     => new AnthropicProvider($keys['anthropic'] ?? '', $useWebSearch),
        $provider === 'gemini'                        => new GeminiProvider($keys['gemini'] ?? '', $useWebSearch),
        in_array($provider, PROVIDERS_OAI_COMPAT)    => new OpenAICompatProvider($provider, $keys[$provider] ?? ''),
        default                                       => throw new RuntimeException('Невідомий провайдер: ' . $provider),
    };
}

// ── Запис чанку в DB ─────────────────────────────────────────────────────────

function write_chunk(PDO $db, string $jobId, string $line): void
{
    $db->prepare('INSERT INTO async_job_chunks (job_id, chunk, created_at) VALUES (?, ?, ?)')
       ->execute([$jobId, $line, date('c')]);
}

function fail_job(PDO $db, string $jobId, string $msg): void
{
    write_chunk($db, $jobId, 'data: ' . json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE));
    write_chunk($db, $jobId, 'data: [DONE]');
    $db->prepare('UPDATE async_jobs SET status = ?, finished_at = ? WHERE id = ?')
       ->execute(['failed', date('c'), $jobId]);
}

// ── Main ─────────────────────────────────────────────────────────────────────

$jobId = trim((string)($argv[1] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "[worker] No job_id provided\n");
    exit(1);
}

$db = get_sqlite_db();
if (!$db) {
    fwrite(STDERR, "[worker] DB unavailable\n");
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM async_jobs WHERE id = ?');
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    fwrite(STDERR, "[worker] Job not found: $jobId\n");
    exit(1);
}

$db->prepare('UPDATE async_jobs SET status = ?, worker_pid = ? WHERE id = ?')
   ->execute(['running', getmypid(), $jobId]);

// Load settings and resolve model
$settings  = load_settings();
$modelsMap = settings_model_map($settings);
$model     = (string)$job['model'];
$modelMeta = $modelsMap[$model] ?? null;

if (!$modelMeta) {
    fail_job($db, $jobId, 'Модель не знайдена: ' . $model);
    exit(1);
}

$provider     = (string)$modelMeta['provider'];
$keys         = $settings['keys'] ?? [];
$useWebSearch = in_array($provider, ['anthropic', 'gemini'], true);
$maxTokens    = (int)$job['max_tokens'];
$prompt           = (string)$job['prompt_text'];
$source           = (string)$job['source_text'];
$sourceRef        = (string)$job['source_ref'];
$extraInstructions = (string)($job['extra_instructions'] ?? '');
$systemPrompt     = (string)$job['system_prompt'];

try {
    $providerObj = w_make_provider($provider, $keys, $useWebSearch);
} catch (RuntimeException $e) {
    fail_job($db, $jobId, $e->getMessage());
    exit(1);
}

$req     = $providerObj->buildRequest($model, $prompt, $systemPrompt, true, $maxTokens);
$payload = json_encode($req['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($payload === false) {
    fail_job($db, $jobId, 'Не вдалось сформувати запит (encoding error)');
    exit(1);
}

// ── Streaming call (з одним авто-повтором при 429) ───────────────────────────

$accText       = '';
$accChunks     = '';
$streamError   = null;
$state         = BaseProvider::initialStreamState();
$httpCodeFinal = 0;
$curlErr       = '';
$timeStart     = microtime(true);
$timeEnd       = $timeStart;
$maxAttempts   = 2;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $accText     = '';
    $accChunks   = '';
    $streamError = null;
    $state       = BaseProvider::initialStreamState();

    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $req['headers'],
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$accText, &$accChunks, &$streamError, &$state, $providerObj, $db, $jobId) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $accChunks .= $chunk;

            if ($httpCode !== 0 && $httpCode !== 200) {
                return strlen($chunk);
            }

            while (($pos = strpos($accChunks, "\n")) !== false) {
                $line      = rtrim(substr($accChunks, 0, $pos), "\r");
                $accChunks = substr($accChunks, $pos + 1);

                if (!str_starts_with($line, 'data: ')) continue;
                $dataStr = substr($line, 6);
                if ($dataStr === '[DONE]' || trim($dataStr) === '') continue;

                $ev = json_decode($dataStr, true);
                if (!is_array($ev)) continue;

                if (isset($ev['error'])) {
                    $streamError = (string)($ev['error']['message'] ?? (is_string($ev['error']) ? $ev['error'] : 'Stream error'));
                    return strlen($chunk);
                }

                $delta = $providerObj->processStreamEvent($ev, $state);
                if ($delta !== null && $delta !== '') {
                    $accText .= $delta;
                    write_chunk($db, $jobId, 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE));
                }
            }

            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $httpCodeFinal = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $timeEnd       = microtime(true);
    $curlErr       = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        fail_job($db, $jobId, 'cURL: ' . $curlErr);
        sqlite_log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => 'cURL: ' . $curlErr]);
        exit(1);
    }

    // Авто-повтор при rate limit (429)
    if ($httpCodeFinal === 429 && $attempt < $maxAttempts) {
        $errResult = json_decode($accChunks, true) ?: [];
        $apiMsg    = $providerObj->normalizeError($errResult, $accChunks);
        $retrySec  = 10;
        if (preg_match('/try again in ([0-9]+(?:\.[0-9]+)?)s/i', $apiMsg, $rm)) {
            $retrySec = min((int)ceil((float)$rm[1]) + 2, 65);
        }
        write_chunk($db, $jobId, 'data: ' . json_encode(['status' => 'Ліміт запитів. Повторна спроба через ' . $retrySec . ' сек…'], JSON_UNESCAPED_UNICODE));
        sleep($retrySec);
        continue;
    }

    break;
}

if ($httpCodeFinal !== 200) {
    $errResult = json_decode($accChunks, true) ?: [];
    $apiMsg    = $providerObj->normalizeError($errResult, $accChunks);
    if ($apiMsg === 'Помилка API') $apiMsg = 'Помилка API (HTTP ' . $httpCodeFinal . ')';
    fail_job($db, $jobId, $apiMsg);
    sqlite_log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => $apiMsg, 'code' => $httpCodeFinal]);
    save_api_response(['ts' => date('c'), 'type' => 'error', 'provider' => $provider, 'model' => $model, 'code' => $httpCodeFinal, 'body' => mb_substr($accChunks, 0, 8000)]);
    exit(1);
}

if ($streamError !== null) {
    fail_job($db, $jobId, $streamError);
    sqlite_log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => $streamError]);
    exit(1);
}

// Flush any data that remained in the buffer without a trailing \n
if (trim($accChunks) !== '') {
    foreach (explode("\n", $accChunks) as $line) {
        $line = rtrim($line, "\r");
        if (!str_starts_with($line, 'data: ')) continue;
        $dataStr = substr($line, 6);
        if ($dataStr === '[DONE]' || trim($dataStr) === '') continue;
        $ev = json_decode($dataStr, true);
        if (!is_array($ev) || isset($ev['error'])) continue;
        $delta = $providerObj->processStreamEvent($ev, $state);
        if ($delta !== null && $delta !== '') {
            $accText .= $delta;
            write_chunk($db, $jobId, 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE));
        }
    }
}

// ── Retry if invalid JSON ─────────────────────────────────────────────────────

$streamOutputTokens = (int)($state['usage']['output_tokens'] ?? 0);
if (!w_valid_json($accText) && $streamOutputTokens < 7500) {
    $retryReq = $providerObj->buildRequest($model, w_retry_prompt($prompt), $systemPrompt, false, $maxTokens);
    $retryPl  = json_encode($retryReq['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($retryPl !== false) {
        $rc = w_non_stream($retryReq['url'], $retryReq['headers'], $retryPl);
        if (!$rc['curl_error'] && $rc['code'] === 200) {
            $retryResult = json_decode($rc['response'], true) ?: [];
            $retryParsed = $providerObj->parseResponse($retryResult);
            if (trim($retryParsed['text']) !== '') {
                write_chunk($db, $jobId, 'data: ' . json_encode(['reset' => true], JSON_UNESCAPED_UNICODE));
                write_chunk($db, $jobId, 'data: ' . json_encode(['delta' => $retryParsed['text']], JSON_UNESCAPED_UNICODE));
                $accText = $retryParsed['text'];
                $ru = $retryParsed['usage'];
                $state['usage']['input_tokens']  = ($state['usage']['input_tokens']  ?? 0) + ($ru['input_tokens']  ?? 0);
                $state['usage']['output_tokens'] = ($state['usage']['output_tokens'] ?? 0) + ($ru['output_tokens'] ?? 0);
            }
        }
    }
}

// ── Finalize ──────────────────────────────────────────────────────────────────

$usage       = $state['usage'];
$webSearch   = $state['web_search_used'];
$durationSec = max(0, $timeEnd - $timeStart);
$cost        = w_calc_cost($usage, $modelMeta);
$cacheStatus = ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit'
    : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache');

write_chunk($db, $jobId, 'data: ' . json_encode([
    'meta'           => true,
    'usage'          => $usage,
    'web_search_used' => $webSearch,
    'cost'           => $cost,
], JSON_UNESCAPED_UNICODE));
write_chunk($db, $jobId, 'data: [DONE]');

$db->prepare('UPDATE async_jobs SET status = ?, finished_at = ? WHERE id = ?')
   ->execute(['done', date('c'), $jobId]);

sqlite_log_request([
    'date' => date('Y-m-d'), 'time' => date('H:i:s'),
    'model' => $model, 'provider' => $provider,
    'inp' => (int)($usage['input_tokens'] ?? 0), 'out' => (int)($usage['output_tokens'] ?? 0),
    'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
    'cache_read'  => (int)($usage['cache_read_input_tokens'] ?? 0),
    'cost' => $cost, 'duration' => number_format($durationSec, 2, '.', ''),
    'prompt_len' => w_strlen($prompt), 'web' => $webSearch, 'cache_status' => $cacheStatus,
]);

if (trim($accText) !== '') {
    save_generation_to_db([
        'model' => $model, 'provider' => $provider, 'source_ref' => $sourceRef,
        'input_text' => $source, 'extra_instructions' => $extraInstructions,
        'output_json' => $accText, 'cost' => $cost,
        'input_tokens' => (int)($usage['input_tokens'] ?? 0),
        'output_tokens' => (int)($usage['output_tokens'] ?? 0),
        'web_search_used' => $webSearch ? 1 : 0,
    ]);
}

save_api_response(['ts' => date('c'), 'type' => 'success', 'provider' => $provider, 'model' => $model, 'code' => 200, 'body' => mb_substr($accText, 0, 8000)]);
