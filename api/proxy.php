<?php
/**
 * proxy.php — проксі для Anthropic + xAI (Grok) + Mistral + Gemini API
 */

define('TIMEOUT', 120);
define('MAX_CHARS', 30000);
define('LOG_FILE', __DIR__ . '/../storage/requests.log');
define('LOG_ON', true);
define('LOG_MAX_BYTES', 5 * 1024 * 1024);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

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

function rotate_log_if_needed($path, $maxBytes) {
  if (!file_exists($path)) return;
  clearstatcache(true, $path);
  $size = filesize($path);
  if ($size === false || $size < $maxBytes) return;

  $rotated = $path . '.1';
  if (file_exists($rotated)) @unlink($rotated);
  @rename($path, $rotated);
}


function calc_request_cost($usage, $modelMeta) {
  $inp = (int)($usage['input_tokens'] ?? 0);
  $out = (int)($usage['output_tokens'] ?? 0);
  $inpPrice = (float)($modelMeta['inp'] ?? 0);
  $outPrice = (float)($modelMeta['out'] ?? 0);
  return ($inp * $inpPrice + $out * $outPrice) / 1000000;
}


function save_api_response($entry) {
  $file = dirname(LOG_FILE) . '/api_responses.json';
  $max  = 5;
  $list = [];
  if (file_exists($file)) {
    $raw = file_get_contents($file);
    if ($raw) $list = json_decode($raw, true) ?: [];
  }
  array_unshift($list, $entry);
  if (count($list) > $max) $list = array_slice($list, 0, $max);
  $encoded = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($encoded !== false) {
    file_put_contents($file, $encoded, LOCK_EX);
  }
}

function write_log_entry($line) {
  $dir = dirname(LOG_FILE);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  if (!is_writable($dir)) {
    return false;
  }
  rotate_log_if_needed(LOG_FILE, LOG_MAX_BYTES);
  return file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX) !== false;
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
$use_web_search = !empty($data['webSearch']) && $provider === 'anthropic' && !empty($modelMeta['web_search']);
$keys = $settings['keys'] ?? ['anthropic' => '', 'xai' => '', 'gemini' => '', 'mistral' => ''];

if ($provider === 'anthropic') {
  $key = trim((string)($keys['anthropic'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'Anthropic API-ключ не задано. Додайте ANTHROPIC_API_KEY у env']);
  $request = ['model' => $model, 'max_tokens' => 4000, 'messages' => [['role' => 'user', 'content' => $prompt]]];
  if ($system_prompt !== '') $request['system'] = [['type' => 'text', 'text' => $system_prompt, 'cache_control' => ['type' => 'ephemeral']]];
  if ($use_web_search) $request['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search']];
  $url = 'https://api.anthropic.com/v1/messages';
  $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'anthropic-beta: prompt-caching-2024-07-31'];
} elseif ($provider === 'xai') {
  $key = trim((string)($keys['xai'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'xAI API-ключ не задано. Додайте XAI_API_KEY у env']);
  $messages = [];
  if ($system_prompt !== '') $messages[] = ['role' => 'system', 'content' => $system_prompt];
  $messages[] = ['role' => 'user', 'content' => $prompt];
  $request = ['model' => $model, 'messages' => $messages, 'max_tokens' => 4000, 'temperature' => 0.4, 'stream' => false];
  $url = 'https://api.x.ai/v1/chat/completions';
  $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
} elseif ($provider === 'mistral') {
  $key = trim((string)($keys['mistral'] ?? ''));
  if ($key === '') send_json(500, ['error' => 'Mistral API-ключ не задано. Додайте MISTRAL_API_KEY у env']);
  $messages = [];
  if ($system_prompt !== '') $messages[] = ['role' => 'system', 'content' => $system_prompt];
  $messages[] = ['role' => 'user', 'content' => $prompt];
  $request = ['model' => $model, 'messages' => $messages, 'max_tokens' => 4000, 'temperature' => 0.4];
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
  $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
  $headers = ['Content-Type: application/json'];
} else {
  send_json(500, ['error' => 'Невідомий провайдер моделі: ' . $provider]);
}

$payload = json_encode($request, JSON_UNESCAPED_UNICODE);
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
    'body'     => mb_substr((string)$response, 0, 8000),
  ]);
  if (LOG_ON) {
    $log_line = build_log_entry_jsonl([
      'date' => date('Y-m-d'),
      'time' => date('H:i:s'),
      'model' => $model,
      'error' => $apiMessage,
      'code' => $httpCode,
      'provider' => $provider,
    ]);
    write_log_entry($log_line);
  }

  send_json($httpCode ?: 500, ['error' => $apiMessage, 'type' => $apiType, 'code' => $httpCode, 'provider' => $provider, 'model' => $model]);
}

$text = '';
$usage = [];
if ($provider === 'anthropic') {
  foreach ($result['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
  $usage = $result['usage'] ?? [];
} elseif ($provider === 'xai' || $provider === 'mistral') {
  $text = $result['choices'][0]['message']['content'] ?? '';
  $u = $result['usage'] ?? [];
  $usage = ['input_tokens' => (int)($u['prompt_tokens'] ?? 0), 'output_tokens' => (int)($u['completion_tokens'] ?? 0), 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
} else {
  $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
  $usage = ['input_tokens' => (int)($result['usageMetadata']['promptTokenCount'] ?? 0), 'output_tokens' => (int)($result['usageMetadata']['candidatesTokenCount'] ?? 0), 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
}

if (trim($text) === '') send_json(500, ['error' => 'Порожня відповідь від API']);

if (LOG_ON) {
  $durationSec = max(0, $timeEnd - $timeStart);
  $cost = calc_request_cost($usage, $modelMeta);
  $log_line = build_log_entry_jsonl([
    'date' => date('Y-m-d'),
    'time' => date('H:i:s'),
    'model' => $model,
    'inp' => (int)($usage['input_tokens'] ?? 0),
    'out' => (int)($usage['output_tokens'] ?? 0),
    'cache_write' => (int)($usage['cache_creation_input_tokens'] ?? 0),
    'cache_read' => (int)($usage['cache_read_input_tokens'] ?? 0),
    'cost' => $cost,
    'duration' => number_format($durationSec, 2, '.', ''),
    'prompt_len' => str_length($prompt),
    'web' => $use_web_search,
    'cache_status' => ((int)($usage['cache_read_input_tokens'] ?? 0) > 0) ? 'cache-hit' : (((int)($usage['cache_creation_input_tokens'] ?? 0) > 0) ? 'cache-write' : 'no-cache'),
  ]);
  write_log_entry($log_line);
}

save_api_response([
  'ts'       => date('c'),
  'type'     => 'success',
  'provider' => $provider,
  'model'    => $model,
  'code'     => 200,
  'body'     => mb_substr((string)$response, 0, 8000),
]);
send_json(200, ['ok' => true, 'text' => $text, 'usage' => $usage, 'meta' => ['provider' => $provider, 'model' => $model]]);
