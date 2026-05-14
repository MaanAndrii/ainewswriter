<?php
require_once __DIR__ . '/../core/app_settings.php';

header('Content-Type: application/json; charset=utf-8');
apply_cors_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
  }

  $action = (string)($data['action'] ?? '');
  if ($action === 'save_models') {
    $models = $data['models'] ?? null;
    if (!is_array($models)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'models must be array']);
      exit;
    }
    $validationError = validate_models_payload($models);
    if ($validationError !== null) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => $validationError]);
      exit;
    }
    $current = load_settings();
    save_settings([
      'models' => $models,
      'system_prompt_custom' => (string)($current['system_prompt_custom'] ?? ''),
      'system_prompt_default_override' => (string)($current['system_prompt_default_override'] ?? ''),
      'prompt_profiles' => $current['prompt_profiles'] ?? get_default_prompt_profiles(),
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_key') {
    $provider = (string)($data['provider'] ?? '');
    $value = trim((string)($data['value'] ?? ''));
    $map = ['anthropic' => 'ANTHROPIC_API_KEY', 'xai' => 'XAI_API_KEY', 'gemini' => 'GEMINI_API_KEY', 'mistral' => 'MISTRAL_API_KEY'];
    if (!isset($map[$provider]) || $value === '') {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid provider or empty value']);
      exit;
    }
    save_env_values([$map[$provider] => $value]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_prompt_profiles') {
    $profiles = $data['profiles'] ?? null;
    if (!is_array($profiles)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'profiles must be object']);
      exit;
    }
    $current = load_settings();
    save_settings([
      'models' => $current['models'] ?? [],
      'system_prompt_custom' => (string)($current['system_prompt_custom'] ?? ''),
      'system_prompt_default_override' => (string)($current['system_prompt_default_override'] ?? ''),
      'prompt_profiles' => $profiles,
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_prompt_limits') {
    $limits = $data['limits'] ?? null;
    if (!is_array($limits)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'limits must be object']);
      exit;
    }
    $current = load_settings();
    $profiles = $current['prompt_profiles'] ?? get_default_prompt_profiles();
    if (!isset($profiles['user']) || !is_array($profiles['user'])) $profiles['user'] = [];
    $profiles['user']['headlines_count'] = max(1, (int)($limits['headlines_count'] ?? 4));
    $profiles['user']['leads_count'] = max(1, (int)($limits['leads_count'] ?? 2));
    $profiles['user']['article_max_chars'] = max(300, (int)($limits['article_max_chars'] ?? 3000));
    $profiles['user']['facebook_max_chars'] = max(50, (int)($limits['facebook_max_chars'] ?? 400));
    save_settings([
      'models' => $current['models'] ?? [],
      'system_prompt_custom' => (string)($current['system_prompt_custom'] ?? ''),
      'system_prompt_default_override' => (string)($current['system_prompt_default_override'] ?? ''),
      'prompt_profiles' => $profiles,
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_system_default_override') {
    $text = trim((string)($data['value'] ?? ''));
    if ($text === '') {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'value must be non-empty']);
      exit;
    }
    $current = load_settings();
    save_settings([
      'models' => $current['models'] ?? [],
      'system_prompt_custom' => (string)($current['system_prompt_custom'] ?? ''),
      'system_prompt_default_override' => $text,
      'prompt_profiles' => $current['prompt_profiles'] ?? get_default_prompt_profiles(),
    ]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'restore_default_prompts') {
    $current = load_settings();
    $defaults = load_prompts_from_json();
    $systemDefault = trim((string)($defaults['system_prompts']['default'] ?? get_default_system_prompt()));
    $profilesDefault = $defaults['user_prompt_profiles']['default'] ?? get_default_prompt_profiles();
    save_settings([
      'models' => $current['models'] ?? [],
      'system_prompt_custom' => '',
      'system_prompt_default_override' => $systemDefault,
      'prompt_profiles' => ['user' => $profilesDefault],
    ]);
    echo json_encode(['ok' => true, 'prompt_system' => $systemDefault, 'prompt_profiles' => ['user' => $profilesDefault]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_password') {
    $target = (string)($data['target'] ?? '');
    $value = trim((string)($data['value'] ?? ''));
    $map = ['admin' => 'ADMIN_PASSWORD', 'logs' => 'LOG_PASSWORD'];
    if (!isset($map[$target]) || strlen($value) < 8) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid target or password too short (min 8)']);
      exit;
    }
    save_env_values([$map[$target] => $value]);
    echo json_encode(['ok' => true]);
    exit;
  }

  // Нова дія: збереження prompts.json
  if ($action === 'save_prompts') {
    $prompts = $data['prompts'] ?? null;
    if (!is_array($prompts)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'prompts must be object']);
      exit;
    }
    if (save_prompts_to_json($prompts)) {
      echo json_encode(['ok' => true]);
    } else {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Failed to save prompts']);
    }
    exit;
  }

  // Export settings (no API keys)
  if ($action === 'export_settings') {
    $settings = load_settings();
    $promptsFile = dirname(__DIR__) . '/prompts.json';
    $promptsData = file_exists($promptsFile) ? json_decode(file_get_contents($promptsFile), true) : [];
    $keys = get_runtime_keys();
    $export = [
      '__version'    => 1,
      '__exported_at' => date('c'),
      'models'       => $settings['models'] ?? [],
      'prompt_profiles' => $settings['prompt_profiles'] ?? get_default_prompt_profiles(),
      'system_prompt_default_override' => (string)($settings['system_prompt_default_override'] ?? ''),
      'prompts_json' => $promptsData,
      'api_keys'     => $keys,
    ];
    echo json_encode(['ok' => true, 'data' => $export], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
  }

  // Import settings
  if ($action === 'import_settings') {
    $payload = $data['data'] ?? null;
    if (!is_array($payload) || ($payload['__version'] ?? 0) < 1) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Невалідний файл імпорту (відсутній __version)']);
      exit;
    }

    $errors = [];
    if (isset($payload['models'])) {
      if (!is_array($payload['models'])) {
        $errors[] = 'models: має бути масив';
      } else {
        $err = validate_models_payload($payload['models']);
        if ($err) $errors[] = 'models: ' . $err;
      }
    }
    if ($errors) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => implode('; ', $errors)]);
      exit;
    }

    $current    = load_settings();
    $newModels  = isset($payload['models'])           ? $payload['models']           : ($current['models'] ?? []);
    $newProfiles= isset($payload['prompt_profiles'])  ? $payload['prompt_profiles']  : ($current['prompt_profiles'] ?? get_default_prompt_profiles());
    $newOverride= array_key_exists('system_prompt_default_override', $payload)
                    ? (string)$payload['system_prompt_default_override']
                    : (string)($current['system_prompt_default_override'] ?? '');

    save_settings([
      'models'       => $newModels,
      'system_prompt_custom' => (string)($current['system_prompt_custom'] ?? ''),
      'system_prompt_default_override' => $newOverride,
      'prompt_profiles' => $newProfiles,
    ]);

    if (isset($payload['prompts_json']) && is_array($payload['prompts_json'])) {
      save_prompts_to_json($payload['prompts_json']);
    }

    $importedKeys = 0;
    if (isset($payload['api_keys']) && is_array($payload['api_keys'])) {
      $keyMap = ['anthropic' => 'ANTHROPIC_API_KEY', 'xai' => 'XAI_API_KEY', 'gemini' => 'GEMINI_API_KEY', 'mistral' => 'MISTRAL_API_KEY'];
      $toSave = [];
      foreach ($keyMap as $provider => $envKey) {
        $val = trim((string)($payload['api_keys'][$provider] ?? ''));
        if ($val !== '') $toSave[$envKey] = $val;
      }
      if ($toSave) { save_env_values($toSave); $importedKeys = count($toSave); }
    }

    echo json_encode(['ok' => true, 'imported' => [
      'models_count'    => count($newModels),
      'has_prompts_json'=> isset($payload['prompts_json']),
      'has_profiles'    => isset($payload['prompt_profiles']),
      'has_system'      => array_key_exists('system_prompt_default_override', $payload),
      'keys_imported'   => $importedKeys,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action']);
  exit;
}

$settings = load_settings();
$models = $settings['models'] ?? [];
$keys = get_runtime_keys();

echo json_encode([
  'models' => $models,
  'keys' => [
    'anthropic' => mask_val($keys['anthropic'] ?? ''),
    'xai' => mask_val($keys['xai'] ?? ''),
    'gemini' => mask_val($keys['gemini'] ?? ''),
    'mistral' => mask_val($keys['mistral'] ?? ''),
  ],
  'default_model' => $models[0]['id'] ?? null,
  'prompt_system' => resolve_system_prompt($settings),
  'prompt_default_override' => (string)($settings['system_prompt_default_override'] ?? ''),
  'prompt_profiles' => $settings['prompt_profiles'] ?? get_default_prompt_profiles(),
  'prompt_profiles_default' => get_default_prompt_profiles(),
], JSON_UNESCAPED_UNICODE);

/**
 * Збереження prompts.json
 */
function save_prompts_to_json($prompts) {
    $promptsFile = dirname(__DIR__) . '/prompts.json';
    $json = json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($promptsFile, $json, LOCK_EX) !== false;
}

function mask_val($value) {
  $value = (string)$value;
  if ($value === '') return 'не задано';
  $len = strlen($value);
  if ($len <= 10) return str_repeat('*', $len);
  return substr($value, 0, 5) . str_repeat('*', max(0, $len - 10)) . substr($value, -5);
}

function validate_models_payload($models) {
  $allowedProviders = ['anthropic', 'xai', 'gemini', 'mistral'];
  $seenIds = [];
  foreach ($models as $idx => $m) {
    if (!is_array($m)) return 'model[' . $idx . '] must be object';
    $id = trim((string)($m['id'] ?? ''));
    $label = trim((string)($m['label'] ?? ''));
    $provider = trim((string)($m['provider'] ?? ''));
    $inp = $m['inp'] ?? null;
    $out = $m['out'] ?? null;

    if ($id === '') return 'model[' . $idx . '].id is required';
    if ($label === '') return 'model[' . $idx . '].label is required';
    if (!in_array($provider, $allowedProviders, true)) return 'model[' . $idx . '].provider invalid';
    if (!is_numeric($inp) || (float)$inp < 0) return 'model[' . $idx . '].inp must be >= 0';
    if (!is_numeric($out) || (float)$out < 0) return 'model[' . $idx . '].out must be >= 0';
    if (isset($seenIds[$id])) return 'duplicate model id: ' . $id;

    $seenIds[$id] = true;
  }
  return null;
}
?>
