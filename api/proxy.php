<?php
/**
 * proxy.php — проксі для Anthropic + xAI (Grok) + Mistral + Gemini API
 * Підтримує звичайний режим і SSE-стрімінг (stream=1 у тілі запиту).
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

function str_length($value) {
  return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function str_slice($value, $start, $length = null) {
  if (function_exists('mb_substr')) {
    return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
  }
  return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function calc_request_cost($usage, $modelMeta) {
  $inp = (int)($usage['input_tokens'] ?? 0);
  $out = (int)($usage['output_tokens'] ?? 0);
  $inpPrice = (float)($modelMeta['inp'] ?? 0);
  $outPrice = (float)($modelMeta['out'] ?? 0);
  return ($inp * $inpPrice + $out * $outPrice) / 1000000;
}

function save_api_response($entry) {
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

function send_json($code, $payload) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = json_encode(['error' => 'Internal encoding error (code ' . $code . ')'], 0);
  }
  echo $json;
  exit;
}

function log_request($logPayload) {
  sqlite_log_request($logPayload);
}

apply_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') send_json(405, ['error' => 'Method not allowed']);

$body = file_get_contents('php://input');
$data = json_decode((string)$body, true);
if (!is_array($data)) send_json(400, ['error' => 'Invalid JSON body']);
if (empty($data['prompt'])) send_json(400, ['error' => 'Missing prompt']);

$prompt = trim((string)$data['prompt']);
if (str_length($prompt) > MAX_CHARS) {
  send_json(400, ['error' => 'Текст занадто довгий (' . str_length($prompt) . ' символів, ліміт ' . MAX_CHARS . ')']);
}

$source     = str_slice((string)($data['source'] ?? ''), 0, 5000);
$sourceRef  = str_slice((string)($data['sourceRef'] ?? ''), 0, 200);
$streamMode = !empty($data['stream']);

$settings = load_settings();
$modelsMap = settings_model_map($settings);
$system_prompt = resolve_system_prompt($settings);
$overridePrompt = trim((string)($data['systemPromptOverride'] ?? ''));
if ($overridePrompt !== '') $system_prompt = $overridePrompt;
if ($system_prompt === '') $system_prompt = get_default_system_prompt();

$model = isset($data['model']) && isset($modelsMap[$data['model']]) ? $data['model'] : ($settings['models'][0]['id'] ?? '');
$modelMeta = $modelsMap[$model] ?? null;
if (!$modelMeta) send_json(500, ['error' => 'Немає доступних моделей у налаштуваннях']);

$provider = (string)$modelMeta['provider'];
$use_web_search = !empty($data['webSearch']) && in_array($provider, ['anthropic', 'gemini'], true) && !empty($modelMeta['web_search']);
$keys = $settings['keys'] ?? ['anthropic' => '', 'xai' => '', 'gemini' => '', 'mistral' => ''];

if ($provider === 'anthropic') {
  $key = trim((string)($keys['anthropic'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'Anthropic API-ключ не задано. Додайте ANTHROPIC_API_KEY у env']);
  $request = ['model' => $model, 'max_tokens' => 4000, 'messages' => [['role' => 'user', 'content' => $prompt]]];
  if ($system_prompt !== '') $request['system'] = [['type' => 'text', 'text' => $system_prompt, 'cache_control' => ['type' => 'ephemeral']]];
  if ($use_web_search) $request['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search']];
  if ($streamMode) $request['stream'] = true;
  $url = 'https://api.anthropic.com/v1/messages';
  $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'anthropic-beta: prompt-caching-2024-07-31'];
} elseif ($provider === 'xai') {
  $key = trim((string)($keys['xai'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'xAI API-ключ не задано. Додайте XAI_API_KEY у env']);
  $messages = [];
  if ($system_prompt !== '') $messages[] = ['role' => 'system', 'content' => $system_prompt];
  $messages[] = ['role' => 'user', 'content' => $prompt];
  $request = ['model' => $model, 'messages' => $messages, 'max_tokens' => 4000, 'temperature' => 0.4, 'stream' => $streamMode];
  if ($streamMode) $request['stream_options'] = ['include_usage' => true];
  $url = 'https://api.x.ai/v1/chat/completions';
  $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
} elseif ($provider === 'mistral') {
  $key = trim((string)($keys['mistral'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'Mistral API-ключ не задано. Додайте MISTRAL_API_KEY у env']);
  $messages = [];
  if ($system_prompt !== '') $messages[] = ['role' => 'system', 'content' => $system_prompt];
  $messages[] = ['role' => 'user', 'content' => $prompt];
  $request = ['model' => $model, 'messages' => $messages, 'max_tokens' => 4000, 'temperature' => 0.4];
  if ($streamMode) $request['stream'] = true;
  $url = 'https://api.mistral.ai/v1/chat/completions';
  $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
} elseif ($provider === 'gemini') {
  $key = trim((string)($keys['gemini'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'Gemini API-ключ не задано. Додайте GEMINI_API_KEY у env']);
  $request = [
    'contents' => [[
      'parts' => [['text' => ($system_prompt !== '' ? $system_prompt . "\n\n" : '') . $prompt]],
    ]],
    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 4000],
  ];
  if ($use_web_search) $request['tools'] = [['google_search' => (object)[]]];
  // Gemini streaming uses streamGenerateContent endpoint
  $geminiEndpoint = $streamMode ? ':streamGenerateContent?alt=sse&key=' : ':generateContent?key=';
  $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . $geminiEndpoint . rawurlencode($key);
  $headers = ['Content-Type: application/json'];
} else {
  send_json(500, ['error' => 'Невідомий провайдер моделі: ' . $provider]);
}

$payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($payload === false) send_json(500, ['error' => 'Не вдалось сформувати запит до API (encoding error)']);

// ── SSE Streaming mode ──────────────────────────────────────────────────────
if ($streamMode) {
  // Disable output buffering
  if (ob_get_level()) ob_end_clean();
  @ini_set('output_buffering', 'off');
  @ini_set('zlib.output_compression', false);

  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  header('X-Accel-Buffering: no');
  header('Connection: keep-alive');

  $accText        = '';
  $accChunks      = ''; // raw SSE buffer for error body
  $usage          = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
  $streamError    = null;
  $web_search_used_stream = false;
  $geminiPrevLen  = 0; // byte offset for Gemini delta tracking

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => TIMEOUT,
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$accText, &$accChunks, &$usage, &$streamError, &$web_search_used_stream, &$geminiPrevLen, $provider) {
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      // Buffer error body for non-200 responses
      if ($httpCode !== 0 && $httpCode !== 200) {
        $accChunks .= $chunk;
        return strlen($chunk);
      }

      $accChunks .= $chunk;
      // Process complete SSE lines
      while (($pos = strpos($accChunks, "\n")) !== false) {
        $line = substr($accChunks, 0, $pos);
        $accChunks = substr($accChunks, $pos + 1);
        $line = rtrim($line, "\r");

        if (!str_starts_with($line, 'data: ')) continue;
        $dataStr = substr($line, 6);
        if ($dataStr === '[DONE]') continue;
        if (trim($dataStr) === '') continue;

        $ev = json_decode($dataStr, true);
        if (!is_array($ev)) continue;

        // Detect error in stream payload
        if (isset($ev['error'])) {
          $streamError = (string)($ev['error']['message'] ?? (is_string($ev['error']) ? $ev['error'] : 'Stream error'));
          return strlen($chunk);
        }

        if ($provider === 'anthropic') {
          $type = $ev['type'] ?? '';
          if ($type === 'message_start') {
            $u = $ev['message']['usage'] ?? [];
            $usage['input_tokens']                = (int)($u['input_tokens'] ?? 0);
            $usage['cache_creation_input_tokens'] = (int)($u['cache_creation_input_tokens'] ?? 0);
            $usage['cache_read_input_tokens']     = (int)($u['cache_read_input_tokens'] ?? 0);
          } elseif ($type === 'content_block_delta') {
            $delta = ($ev['delta']['type'] ?? '') === 'text_delta' ? ($ev['delta']['text'] ?? '') : '';
            if ($delta !== '') {
              $accText .= $delta;
              echo 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
              flush();
            }
          } elseif ($type === 'message_delta') {
            $u = $ev['usage'] ?? [];
            $usage['output_tokens'] = (int)($u['output_tokens'] ?? $usage['output_tokens']);
          } elseif ($type === 'content_block_start') {
            // Check for tool_use blocks (web search)
            if (($ev['content_block']['type'] ?? '') === 'tool_use') {
              $web_search_used_stream = true;
            }
          }
        } elseif ($provider === 'xai' || $provider === 'mistral') {
          // OpenAI-compatible format
          $choices = $ev['choices'] ?? [];
          if (!empty($choices[0]['delta']['content'])) {
            $delta = (string)$choices[0]['delta']['content'];
            $accText .= $delta;
            echo 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
          }
          // Usage may appear in any chunk
          if (isset($ev['usage'])) {
            $u = $ev['usage'];
            if (isset($u['prompt_tokens']))     $usage['input_tokens']  = (int)$u['prompt_tokens'];
            if (isset($u['completion_tokens'])) $usage['output_tokens'] = (int)$u['completion_tokens'];
          }
        } elseif ($provider === 'gemini') {
          // Gemini streaming: each chunk is a full cumulative text object.
          // Track byte offset and emit only new bytes as delta.
          $fullText = $ev['candidates'][0]['content']['parts'][0]['text'] ?? '';
          if ($fullText !== '') {
            $delta = substr($fullText, $geminiPrevLen);
            if ($delta !== '') {
              $geminiPrevLen += strlen($delta);
              $accText .= $delta;
              echo 'data: ' . json_encode(['delta' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
              flush();
            }
          }
          // Detect Gemini web search usage
          if (!empty($ev['candidates'][0]['groundingMetadata'])) {
            $web_search_used_stream = true;
          }
          if (isset($ev['usageMetadata'])) {
            $usage['input_tokens']  = (int)($ev['usageMetadata']['promptTokenCount'] ?? 0);
            $usage['output_tokens'] = (int)($ev['usageMetadata']['candidatesTokenCount'] ?? 0);
          }
        }
      }

      return strlen($chunk);
    },
  ]);

  $timeStart = microtime(true);
  curl_exec($ch);
  $httpCodeFinal = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $timeEnd = microtime(true);
  $curlErr = curl_error($ch);
  curl_close($ch);

  if ($curlErr) {
    echo 'data: ' . json_encode(['error' => 'cURL: ' . $curlErr], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
  }

  if ($httpCodeFinal !== 200) {
    $apiMsg = '';
    if ($accChunks !== '') {
      $errResult = json_decode($accChunks, true);
      if (is_array($errResult)) {
        $apiMsg = (string)($errResult['error']['message'] ?? $errResult['message'] ?? $errResult['detail'] ?? '');
      }
      if ($apiMsg === '') $apiMsg = trim($accChunks);
      if (str_length($apiMsg) > 500) $apiMsg = str_slice($apiMsg, 0, 500) . '...';
    }
    if ($apiMsg === '') $apiMsg = 'Помилка API (HTTP ' . $httpCodeFinal . ')';

    log_request([
      'date' => date('Y-m-d'), 'time' => date('H:i:s'),
      'model' => $model, 'provider' => $provider,
      'error' => $apiMsg, 'code' => $httpCodeFinal,
    ]);
    save_api_response([
      'ts' => date('c'), 'type' => 'error', 'provider' => $provider,
      'model' => $model, 'code' => $httpCodeFinal,
      'body' => str_slice($accChunks, 0, 8000),
    ]);

    echo 'data: ' . json_encode(['error' => $apiMsg], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
  }

  if ($streamError !== null) {
    echo 'data: ' . json_encode(['error' => $streamError], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();

    log_request([
      'date' => date('Y-m-d'), 'time' => date('H:i:s'),
      'model' => $model, 'provider' => $provider,
      'error' => $streamError,
    ]);
    exit;
  }

  // Emit meta event with usage info
  $durationSec  = max(0, $timeEnd - $timeStart);
  $cost         = calc_request_cost($usage, $modelMeta);
  $cache_status = ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit'
    : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache');

  echo 'data: ' . json_encode([
    'meta' => true,
    'usage' => $usage,
    'web_search_used' => $web_search_used_stream,
    'cost' => $cost,
  ], JSON_UNESCAPED_UNICODE) . "\n\n";
  echo "data: [DONE]\n\n";
  flush();

  // Log to SQLite + JSONL
  log_request([
    'date' => date('Y-m-d'),
    'time' => date('H:i:s'),
    'model' => $model,
    'provider' => $provider,
    'inp' => (int)($usage['input_tokens'] ?? 0),
    'out' => (int)($usage['output_tokens'] ?? 0),
    'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
    'cache_read' => (int)($usage['cache_read_input_tokens'] ?? 0),
    'cost' => $cost,
    'duration' => number_format($durationSec, 2, '.', ''),
    'prompt_len' => str_length($prompt),
    'web' => $web_search_used_stream,
    'cache_status' => $cache_status,
  ]);

  // Save generation to SQLite
  if (trim($accText) !== '') {
    save_generation_to_db([
      'model' => $model,
      'provider' => $provider,
      'source_ref' => $sourceRef,
      'input_text' => $source,
      'output_json' => $accText,
      'cost' => $cost,
      'input_tokens' => (int)($usage['input_tokens'] ?? 0),
      'output_tokens' => (int)($usage['output_tokens'] ?? 0),
      'web_search_used' => $web_search_used_stream ? 1 : 0,
    ]);
  }

  // Save raw streamed response for API panel
  save_api_response([
    'ts'       => date('c'),
    'type'     => 'success',
    'provider' => $provider,
    'model'    => $model,
    'code'     => 200,
    'body'     => str_slice($accText, 0, 8000),
  ]);

  exit;
}

// ── Non-streaming mode ──────────────────────────────────────────────────────
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_TIMEOUT => TIMEOUT,
]);
$timeStart = microtime(true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$timeEnd = microtime(true);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) send_json(502, ['error' => 'cURL: ' . $curlError]);

$result = json_decode((string)$response, true);
if ($httpCode !== 200) {
  $apiMessage = '';
  $apiType = '';
  if (is_array($result)) {
    $apiMessage = (string)($result['error']['message'] ?? $result['message'] ?? $result['detail'] ?? '');
    $apiType = (string)($result['error']['type'] ?? $result['type'] ?? '');
  }
  if ($apiMessage === '') {
    $apiMessage = trim((string)$response);
    if ($apiMessage === '') $apiMessage = 'Помилка API';
    if (str_length($apiMessage) > 500) $apiMessage = str_slice($apiMessage, 0, 500) . '...';
  }

  save_api_response([
    'ts'       => date('c'),
    'type'     => 'error',
    'provider' => $provider,
    'model'    => $model,
    'code'     => $httpCode,
    'body'     => str_slice((string)$response, 0, 8000),
  ]);

  log_request([
    'date' => date('Y-m-d'),
    'time' => date('H:i:s'),
    'model' => $model,
    'error' => $apiMessage,
    'code' => $httpCode,
    'provider' => $provider,
  ]);

  send_json($httpCode ?: 500, ['error' => $apiMessage, 'type' => $apiType, 'code' => $httpCode, 'provider' => $provider, 'model' => $model]);
}

$text = '';
$usage = [];
$web_search_used = false;
if ($provider === 'anthropic') {
  foreach ($result['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'web_search') $web_search_used = true;
    if (($block['type'] ?? '') === 'tool_result') $web_search_used = true;
  }
  $usage = $result['usage'] ?? [];
} elseif ($provider === 'xai') {
  $content = $result['choices'][0]['message']['content'] ?? '';
  $text = is_array($content) ? '' : (string)$content;
  $u = $result['usage'] ?? [];
  $usage = ['input_tokens' => (int)($u['prompt_tokens'] ?? 0), 'output_tokens' => (int)($u['completion_tokens'] ?? 0), 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
  $citations = $result['citations'] ?? $result['choices'][0]['citations'] ?? [];
  $web_search_used = !empty($citations);
} elseif ($provider === 'mistral') {
  $content = $result['choices'][0]['message']['content'] ?? '';
  $text = is_array($content) ? '' : (string)$content;
  $u = $result['usage'] ?? [];
  $usage = ['input_tokens' => (int)($u['prompt_tokens'] ?? 0), 'output_tokens' => (int)($u['completion_tokens'] ?? 0), 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
} else {
  $text = (string)($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
  $usage = ['input_tokens' => (int)($result['usageMetadata']['promptTokenCount'] ?? 0), 'output_tokens' => (int)($result['usageMetadata']['candidatesTokenCount'] ?? 0), 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
  $groundingMeta = $result['candidates'][0]['groundingMetadata'] ?? [];
  $web_search_used = !empty($groundingMeta);
}

if (trim($text) === '') send_json(500, ['error' => 'Порожня відповідь від API']);

$durationSec  = max(0, $timeEnd - $timeStart);
$cost         = calc_request_cost($usage, $modelMeta);
$cache_status = ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit'
  : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache');

log_request([
  'date' => date('Y-m-d'),
  'time' => date('H:i:s'),
  'model' => $model,
  'provider' => $provider,
  'inp' => (int)($usage['input_tokens'] ?? 0),
  'out' => (int)($usage['output_tokens'] ?? 0),
  'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
  'cache_read' => (int)($usage['cache_read_input_tokens'] ?? 0),
  'cost' => $cost,
  'duration' => number_format($durationSec, 2, '.', ''),
  'prompt_len' => str_length($prompt),
  'web' => $web_search_used,
  'cache_status' => $cache_status,
]);

// Save generation to SQLite
save_generation_to_db([
  'model' => $model,
  'provider' => $provider,
  'source_ref' => $sourceRef,
  'input_text' => $source,
  'output_json' => $text,
  'cost' => $cost,
  'input_tokens' => (int)($usage['input_tokens'] ?? 0),
  'output_tokens' => (int)($usage['output_tokens'] ?? 0),
  'web_search_used' => $web_search_used ? 1 : 0,
]);

save_api_response([
  'ts'       => date('c'),
  'type'     => 'success',
  'provider' => $provider,
  'model'    => $model,
  'code'     => 200,
  'body'     => str_slice((string)$response, 0, 8000),
]);
send_json(200, ['ok' => true, 'text' => $text, 'usage' => $usage, 'meta' => ['provider' => $provider, 'model' => $model, 'web_search_used' => $web_search_used]]);
