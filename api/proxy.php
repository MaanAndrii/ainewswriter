<?php
/**
 * proxy.php — HTTP-проксі між фронтендом і LLM API.
 *
 * Provider-специфічна логіка винесена в api/providers/:
 *   AnthropicProvider    — Anthropic Claude (prompt caching, web search)
 *   GeminiProvider       — Google Gemini (grounding, web search)
 *   OpenAICompatProvider — xAI, Mistral, OpenAI, DeepSeek
 *
 * Щоб додати новий провайдер: створити клас у api/providers/, підключити
 * у функції make_provider() нижче — більше нічого не змінювати.
 */

define('TIMEOUT', 120);
define('MAX_CHARS', 30000);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

set_exception_handler(function (Throwable $e) {
    send_json(500, ['error' => 'Внутрішня помилка сервера: ' . $e->getMessage()]);
});

require_once __DIR__ . '/../core/app_settings.php';
require_once __DIR__ . '/providers/BaseProvider.php';
require_once __DIR__ . '/providers/AnthropicProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/OpenAICompatProvider.php';

// ── Утиліти ─────────────────────────────────────────────────────────────────

function str_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function str_slice(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function calc_request_cost(array $usage, array $modelMeta): float
{
    $inp      = (int)($usage['input_tokens']  ?? 0);
    $out      = (int)($usage['output_tokens'] ?? 0);
    $inpPrice = (float)($modelMeta['inp']     ?? 0);
    $outPrice = (float)($modelMeta['out']     ?? 0);
    return ($inp * $inpPrice + $out * $outPrice) / 1_000_000;
}

function send_json(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['error' => 'Internal encoding error (code ' . $code . ')']);
    }
    echo $json;
    exit;
}

function save_api_response(array $entry): void
{
    $file = APP_ROOT . '/storage/api_responses.json';
    $list = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        if ($raw) $list = json_decode($raw, true) ?: [];
    }
    array_unshift($list, $entry);
    if (count($list) > 5) $list = array_slice($list, 0, 5);
    $encoded = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded !== false) {
        file_put_contents($file, $encoded, LOCK_EX);
    }
}

function log_request(array $payload): void
{
    sqlite_log_request($payload);
}

// ── JSON-валідація відповіді ─────────────────────────────────────────────────

/**
 * Витягує перший JSON-об'єкт з тексту (ігнорує markdown-огорожі).
 * Повертає null якщо JSON-об'єкт не знайдено або не парсується.
 */
function extract_and_parse_json(string $text): ?array
{
    $text = trim($text);
    // Strip ```json ... ``` or ``` ... ``` fences
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($parsed) && count($parsed) > 0 ? $parsed : null;
}

function is_valid_json_response(string $text): bool
{
    return extract_and_parse_json($text) !== null;
}

/**
 * Виконує одиночний не-стримінговий HTTP-запит до LLM API.
 * Повертає ['response' => string, 'code' => int, 'curl_error' => string].
 */
function do_non_stream_call(string $url, array $headers, string $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => TIMEOUT,
    ]);
    $response  = (string)curl_exec($ch);
    $code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'code' => $code, 'curl_error' => $curlErr];
}

/**
 * Формує повідомлення про помилку та уточнений запит для повторної спроби.
 */
function build_retry_prompt(string $originalPrompt): string
{
    return $originalPrompt
        . "\n\nКРИТИЧНО: попередня відповідь не містила валідного JSON-об'єкта. "
        . "Поверни ВИКЛЮЧНО валідний JSON-об'єкт (починай з {, закінчуй }), без будь-якого іншого тексту.";
}

// ── Фабрика провайдерів ──────────────────────────────────────────────────────

function make_provider(string $provider, array $keys, bool $useWebSearch): BaseProvider
{
    $oaiProviders = ['xai', 'mistral', 'openai', 'deepseek', 'groq'];

    return match (true) {
        $provider === 'anthropic'             => new AnthropicProvider($keys['anthropic'] ?? '', $useWebSearch),
        $provider === 'gemini'                => new GeminiProvider($keys['gemini'] ?? '', $useWebSearch),
        in_array($provider, $oaiProviders)    => new OpenAICompatProvider($provider, $keys[$provider] ?? ''),
        default                               => throw new RuntimeException('Невідомий провайдер: ' . $provider),
    };
}

// ── Вхідний запит ────────────────────────────────────────────────────────────

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') send_json(405, ['error' => 'Method not allowed']);

$body = file_get_contents('php://input');
$data = json_decode((string)$body, true);
if (!is_array($data))        send_json(400, ['error' => 'Invalid JSON body']);
if (empty($data['prompt']))  send_json(400, ['error' => 'Missing prompt']);

$prompt = trim((string)$data['prompt']);
if (str_length($prompt) > MAX_CHARS) {
    send_json(400, ['error' => 'Текст занадто довгий (' . str_length($prompt) . ' символів, ліміт ' . MAX_CHARS . ')']);
}

$source     = str_slice((string)($data['source']    ?? ''), 0, 5000);
$sourceRef  = str_slice((string)($data['sourceRef'] ?? ''), 0, 200);
$streamMode = !empty($data['stream']);

$settings      = load_settings();
$modelsMap     = settings_model_map($settings);
$system_prompt = resolve_system_prompt($settings);

$overridePrompt = trim((string)($data['systemPromptOverride'] ?? ''));
if ($overridePrompt !== '') $system_prompt = $overridePrompt;
if ($system_prompt === '')  $system_prompt = get_default_system_prompt();

$model     = isset($data['model']) && isset($modelsMap[$data['model']]) ? $data['model'] : ($settings['models'][0]['id'] ?? '');
$modelMeta = $modelsMap[$model] ?? null;
if (!$modelMeta) send_json(500, ['error' => 'Немає доступних моделей у налаштуваннях']);

$provider      = (string)$modelMeta['provider'];
$useWebSearch  = in_array($provider, ['anthropic', 'gemini'], true);
$keys          = $settings['keys'] ?? [];

// ── Ініціалізація провайдера ─────────────────────────────────────────────────

try {
    $providerObj = make_provider($provider, $keys, $useWebSearch);
} catch (RuntimeException $e) {
    send_json(500, ['error' => $e->getMessage()]);
}

$req = $providerObj->buildRequest($model, $prompt, $system_prompt, $streamMode);

$payload = json_encode($req['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($payload === false) send_json(500, ['error' => 'Не вдалось сформувати запит до API (encoding error)']);

// ── SSE Streaming mode ───────────────────────────────────────────────────────

if ($streamMode) {
    if (ob_get_level()) ob_end_clean();
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $accText    = '';
    $accChunks  = '';
    $streamError = null;
    $state      = BaseProvider::initialStreamState();

    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $req['headers'],
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$accText, &$accChunks, &$streamError, &$state, $providerObj) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 0 && $httpCode !== 200) {
                $accChunks .= $chunk;
                return strlen($chunk);
            }

            $accChunks .= $chunk;

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
                    echo 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                }
            }

            return strlen($chunk);
        },
    ]);

    $timeStart    = microtime(true);
    curl_exec($ch);
    $httpCodeFinal = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $timeEnd      = microtime(true);
    $curlErr      = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo 'data: ' . json_encode(['error' => 'cURL: ' . $curlErr], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }

    if ($httpCodeFinal !== 200) {
        $errResult = json_decode($accChunks, true) ?: [];
        $apiMsg    = $providerObj->normalizeError($errResult, $accChunks);
        if ($apiMsg === 'Помилка API') $apiMsg = 'Помилка API (HTTP ' . $httpCodeFinal . ')';

        log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => $apiMsg, 'code' => $httpCodeFinal]);
        save_api_response(['ts' => date('c'), 'type' => 'error', 'provider' => $provider, 'model' => $model, 'code' => $httpCodeFinal, 'body' => str_slice($accChunks, 0, 8000)]);

        echo 'data: ' . json_encode(['error' => $apiMsg], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }

    if ($streamError !== null) {
        echo 'data: ' . json_encode(['error' => $streamError], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => $streamError]);
        exit;
    }

    // ── Серверна валідація JSON + повторна спроба (streaming) ───────────────
    // Якщо output_tokens досяг ліміту — відповідь обрізана, повтор з тим
    // самим лімітом не допоможе; залишаємо обробку фронтенду.
    $streamOutputTokens = (int)($state['usage']['output_tokens'] ?? 0);
    if (!is_valid_json_response($accText) && $streamOutputTokens < 7500) {
        $retryReq2 = $providerObj->buildRequest($model, build_retry_prompt($prompt), $system_prompt, false);
        $retryPl2  = json_encode($retryReq2['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($retryPl2 !== false) {
            $rc = do_non_stream_call($retryReq2['url'], $retryReq2['headers'], $retryPl2);
            if (!$rc['curl_error'] && $rc['code'] === 200) {
                $retryResult2 = json_decode($rc['response'], true) ?: [];
                $retryParsed2 = $providerObj->parseResponse($retryResult2);
                if (trim($retryParsed2['text']) !== '') {
                    echo 'data: ' . json_encode(['reset' => true], JSON_UNESCAPED_UNICODE) . "\n\n";
                    echo 'data: ' . json_encode(['delta' => $retryParsed2['text']], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                    $accText = $retryParsed2['text'];
                    $ru = $retryParsed2['usage'];
                    $state['usage']['input_tokens']  = ($state['usage']['input_tokens']  ?? 0) + ($ru['input_tokens']  ?? 0);
                    $state['usage']['output_tokens'] = ($state['usage']['output_tokens'] ?? 0) + ($ru['output_tokens'] ?? 0);
                }
            }
        }
    }

    $usage       = $state['usage'];
    $webSearch   = $state['web_search_used'];
    $durationSec = max(0, $timeEnd - $timeStart);
    $cost        = calc_request_cost($usage, $modelMeta);
    $cacheStatus = ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit'
        : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache');

    echo 'data: ' . json_encode(['meta' => true, 'usage' => $usage, 'web_search_used' => $webSearch, 'cost' => $cost], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();

    log_request([
        'date' => date('Y-m-d'), 'time' => date('H:i:s'),
        'model' => $model, 'provider' => $provider,
        'inp' => (int)($usage['input_tokens'] ?? 0), 'out' => (int)($usage['output_tokens'] ?? 0),
        'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
        'cache_read'  => (int)($usage['cache_read_input_tokens'] ?? 0),
        'cost' => $cost, 'duration' => number_format($durationSec, 2, '.', ''),
        'prompt_len' => str_length($prompt), 'web' => $webSearch, 'cache_status' => $cacheStatus,
    ]);

    if (trim($accText) !== '') {
        save_generation_to_db([
            'model' => $model, 'provider' => $provider, 'source_ref' => $sourceRef,
            'input_text' => $source, 'output_json' => $accText, 'cost' => $cost,
            'input_tokens' => (int)($usage['input_tokens'] ?? 0),
            'output_tokens' => (int)($usage['output_tokens'] ?? 0),
            'web_search_used' => $webSearch ? 1 : 0,
        ]);
    }

    save_api_response(['ts' => date('c'), 'type' => 'success', 'provider' => $provider, 'model' => $model, 'code' => 200, 'body' => str_slice($accText, 0, 8000)]);
    exit;
}

// ── Non-streaming mode ───────────────────────────────────────────────────────

$ch = curl_init($req['url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $req['headers'],
    CURLOPT_TIMEOUT        => TIMEOUT,
]);

$timeStart = microtime(true);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$timeEnd   = microtime(true);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) send_json(502, ['error' => 'cURL: ' . $curlError]);

$result = json_decode((string)$response, true) ?: [];

if ($httpCode !== 200) {
    $apiMessage = $providerObj->normalizeError($result, (string)$response);
    $apiType    = (string)($result['error']['type'] ?? $result['type'] ?? '');

    save_api_response(['ts' => date('c'), 'type' => 'error', 'provider' => $provider, 'model' => $model, 'code' => $httpCode, 'body' => str_slice((string)$response, 0, 8000)]);
    log_request(['date' => date('Y-m-d'), 'time' => date('H:i:s'), 'model' => $model, 'provider' => $provider, 'error' => $apiMessage, 'code' => $httpCode]);

    send_json($httpCode ?: 500, ['error' => $apiMessage, 'type' => $apiType, 'code' => $httpCode, 'provider' => $provider, 'model' => $model]);
}

$parsed      = $providerObj->parseResponse($result);
$text        = $parsed['text'];
$usage       = $parsed['usage'];
$webSearch   = $parsed['web_search_used'];

if (trim($text) === '') send_json(500, ['error' => 'Порожня відповідь від API']);

// ── Серверна валідація JSON + повторна спроба (non-streaming) ────────────────
// Пропускаємо повтор якщо модель обрізала відповідь через ліміт токенів.
$nsOutputTokens = (int)($usage['output_tokens'] ?? 0);
if (!is_valid_json_response($text) && $nsOutputTokens < 7500) {
    $retryPromptNS = build_retry_prompt($prompt);
    for ($retryAttempt = 0; $retryAttempt < 2; $retryAttempt++) {
        $nsReq = $providerObj->buildRequest($model, $retryPromptNS, $system_prompt, false);
        $nsPl  = json_encode($nsReq['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($nsPl === false) break;
        $nsCall = do_non_stream_call($nsReq['url'], $nsReq['headers'], $nsPl);
        if ($nsCall['curl_error'] || $nsCall['code'] !== 200) break;
        $nsResult = json_decode($nsCall['response'], true) ?: [];
        $nsParsed = $providerObj->parseResponse($nsResult);
        if (trim($nsParsed['text']) !== '') {
            $ru = $nsParsed['usage'];
            $usage['input_tokens']  = ($usage['input_tokens']  ?? 0) + ($ru['input_tokens']  ?? 0);
            $usage['output_tokens'] = ($usage['output_tokens'] ?? 0) + ($ru['output_tokens'] ?? 0);
            $text = $nsParsed['text'];
            break;
        }
    }
}

$durationSec = max(0, $timeEnd - $timeStart);
$cost        = calc_request_cost($usage, $modelMeta);
$cacheStatus = ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit'
    : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache');

log_request([
    'date' => date('Y-m-d'), 'time' => date('H:i:s'),
    'model' => $model, 'provider' => $provider,
    'inp' => (int)($usage['input_tokens'] ?? 0), 'out' => (int)($usage['output_tokens'] ?? 0),
    'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
    'cache_read'  => (int)($usage['cache_read_input_tokens'] ?? 0),
    'cost' => $cost, 'duration' => number_format($durationSec, 2, '.', ''),
    'prompt_len' => str_length($prompt), 'web' => $webSearch, 'cache_status' => $cacheStatus,
]);

save_generation_to_db([
    'model' => $model, 'provider' => $provider, 'source_ref' => $sourceRef,
    'input_text' => $source, 'output_json' => $text, 'cost' => $cost,
    'input_tokens' => (int)($usage['input_tokens'] ?? 0),
    'output_tokens' => (int)($usage['output_tokens'] ?? 0),
    'web_search_used' => $webSearch ? 1 : 0,
]);

save_api_response(['ts' => date('c'), 'type' => 'success', 'provider' => $provider, 'model' => $model, 'code' => 200, 'body' => str_slice((string)$response, 0, 8000)]);

send_json(200, ['ok' => true, 'text' => $text, 'usage' => $usage, 'meta' => ['provider' => $provider, 'model' => $model, 'web_search_used' => $webSearch]]);
