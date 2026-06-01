<?php
require_once __DIR__ . '/../core/app_settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
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
    update_settings(['models' => $models]);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_key') {
    $provider = (string)($data['provider'] ?? '');
    $value = trim((string)($data['value'] ?? ''));
    $map = get_provider_env_map();
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
    $profileErr = validate_prompt_profiles_payload($profiles);
    if ($profileErr !== null) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => $profileErr]);
      exit;
    }
    update_settings(['prompt_profiles' => $profiles]);
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
    $current  = load_settings();
    $profiles = $current['prompt_profiles'] ?? get_default_prompt_profiles();
    if (!isset($profiles['user']) || !is_array($profiles['user'])) $profiles['user'] = [];
    $profiles['user']['headlines_count']    = max(1,   (int)($limits['headlines_count']    ?? 4));
    $profiles['user']['leads_count']        = max(1,   (int)($limits['leads_count']        ?? 2));
    $profiles['user']['article_max_chars']  = max(300, (int)($limits['article_max_chars']  ?? 3000));
    $profiles['user']['facebook_max_chars'] = max(50,  (int)($limits['facebook_max_chars'] ?? 400));
    $profiles['user']['lead_min_chars']     = max(50,  (int)($limits['lead_min_chars']     ?? 150));
    $profiles['user']['lead_max_chars']     = max(50,  (int)($limits['lead_max_chars']     ?? 180));
    update_settings(['prompt_profiles' => $profiles]);
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
    update_settings(['system_prompt_default_override' => $text]);
    echo json_encode(['ok' => true]);
    exit;
  }

  // Валідація і витягування полів для save_all_prompts / save_all_as_default
  function extract_prompt_payload(array $data): array|string {
    $systemText = trim((string)($data['system'] ?? ''));
    $profiles   = $data['profiles'] ?? null;
    if ($systemText === '') return 'system must be non-empty';
    if (!is_array($profiles)) return 'profiles must be object';
    $profileErr = validate_prompt_profiles_payload($profiles);
    if ($profileErr !== null) return $profileErr;
    return ['system' => $systemText, 'profiles' => $profiles];
  }

  // ▶ Зберегти промти — runtime + prompts.json (з бекапом)
  if ($action === 'save_all_prompts') {
    $res = extract_prompt_payload($data);
    if (is_string($res)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$res]); exit; }
    update_settings(['system_prompt_default_override' => $res['system'], 'prompt_profiles' => $res['profiles']]);
    $newDefaults = [
      'system_prompts'       => ['default' => $res['system']],
      'user_prompt_profiles' => ['default' => $res['profiles']['user'] ?? []],
    ];
    if (save_prompts_to_json($newDefaults)) {
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Не вдалося записати prompts.json — перевірте права на файл']);
    }
    exit;
  }

  // ★ Зберегти як за замовчуванням — runtime + prompts.json (з бекапом)
  if ($action === 'save_all_as_default') {
    $res = extract_prompt_payload($data);
    if (is_string($res)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$res]); exit; }
    update_settings(['system_prompt_default_override' => $res['system'], 'prompt_profiles' => $res['profiles']]);
    $newDefaults = [
      'system_prompts'       => ['default' => $res['system']],
      'user_prompt_profiles' => ['default' => $res['profiles']['user'] ?? []],
    ];
    if (save_prompts_to_json($newDefaults)) {
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Не вдалося записати prompts.json — перевірте права на файл']);
    }
    exit;
  }

  if ($action === 'get_prompt_backup') {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($data['name'] ?? ''));
    $file = dirname(__DIR__) . '/storage/prompt_backups/' . $name . '.json';
    if (!file_exists($file)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Бекап не знайдено']); exit; }
    $content = json_decode(file_get_contents($file), true);
    if (!is_array($content)) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Файл пошкоджений']); exit; }
    echo json_encode(['ok' => true, 'content' => $content], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'delete_prompt_backup') {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($data['name'] ?? ''));
    $file = dirname(__DIR__) . '/storage/prompt_backups/' . $name . '.json';
    if (!file_exists($file)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Бекап не знайдено']); exit; }
    @unlink($file);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_as_default_prompts') {
    $current = load_settings();
    $systemPrompt = trim((string)(
      $current['system_prompt_default_override'] !== ''
        ? $current['system_prompt_default_override']
        : ($current['system_prompt_custom'] ?? get_default_system_prompt())
    ));
    $profiles = $current['prompt_profiles']['user'] ?? get_default_prompt_profiles()['user'] ?? [];
    $newDefaults = [
      'system_prompts' => ['default' => $systemPrompt],
      'user_prompt_profiles' => ['default' => $profiles],
    ];
    if (save_prompts_to_json($newDefaults)) {
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Не вдалося записати prompts.json — перевірте права на файл']);
    }
    exit;
  }

  if ($action === 'restore_default_prompts') {
    $defaults = load_prompts_from_json();
    $systemDefault = trim((string)($defaults['system_prompts']['default'] ?? get_default_system_prompt()));
    $profilesDefault = $defaults['user_prompt_profiles']['default'] ?? get_default_prompt_profiles();
    update_settings(['system_prompt_custom' => '', 'system_prompt_default_override' => $systemDefault, 'prompt_profiles' => ['user' => $profilesDefault]]);
    echo json_encode(['ok' => true, 'prompt_system' => $systemDefault, 'prompt_profiles' => ['user' => $profilesDefault]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_password') {
    $target = (string)($data['target'] ?? '');
    $value = trim((string)($data['value'] ?? ''));
    $map = ['admin' => 'ADMIN_PASSWORD'];
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
    $promptsErr = validate_prompts_json_payload($prompts);
    if ($promptsErr !== null) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => $promptsErr]);
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

  if ($action === 'get_prompt_backups') {
    echo json_encode(['ok' => true, 'backups' => list_prompt_backups()], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'restore_prompt_backup') {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($data['name'] ?? ''));
    $dir  = dirname(__DIR__) . '/storage/prompt_backups';
    $file = "$dir/$name.json";
    if (!file_exists($file)) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'Бекап не знайдено']);
      exit;
    }
    $raw    = file_get_contents($file);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
      http_response_code(422);
      echo json_encode(['ok' => false, 'error' => 'Файл бекапу пошкоджений (невалідний JSON)']);
      exit;
    }
    $err = validate_prompts_json_payload($parsed);
    if ($err !== null) {
      http_response_code(422);
      echo json_encode(['ok' => false, 'error' => 'Файл бекапу пошкоджений: ' . $err]);
      exit;
    }
    // 1. Зберігаємо prompts.json (поточний стан стане новим бекапом)
    if (!save_prompts_to_json($parsed)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Не вдалося записати prompts.json']);
      exit;
    }
    // 2. Застосовуємо до runtime settings_store.php
    $systemPrompt   = trim((string)($parsed['system_prompts']['default'] ?? get_default_system_prompt()));
    $profilesUser   = $parsed['user_prompt_profiles']['default'] ?? get_default_prompt_profiles()['user'] ?? [];
    update_settings(['system_prompt_custom' => '', 'system_prompt_default_override' => $systemPrompt, 'prompt_profiles' => ['user' => $profilesUser]]);
    echo json_encode([
      'ok'             => true,
      'prompt_system'  => $systemPrompt,
      'prompt_profiles'=> ['user' => $profilesUser],
    ], JSON_UNESCAPED_UNICODE);
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
      'prompt_profiles' => (function() use ($settings) {
    $defaults = get_default_prompt_profiles();
    $saved    = $settings['prompt_profiles'] ?? [];
    // Мерджимо дефолт з збереженим — нові поля з prompts.json завжди присутні
    if (!empty($saved['user']) && is_array($saved['user'])) {
      $saved['user'] = array_merge($defaults['user'] ?? [], $saved['user']);
    } else {
      $saved = $defaults;
    }
    return $saved;
  })(),
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

    update_settings([
      'models'                         => $newModels,
      'system_prompt_default_override' => $newOverride,
      'prompt_profiles'                => $newProfiles,
    ]);

    if (isset($payload['prompts_json']) && is_array($payload['prompts_json'])) {
      save_prompts_to_json($payload['prompts_json']);
    }

    $importedKeys = 0;
    if (isset($payload['api_keys']) && is_array($payload['api_keys'])) {
      $keyMap = get_provider_env_map();
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

  if ($action === 'save_post_processing') {
    $pp = $data['post_processing'] ?? null;
    if (!is_array($pp)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'post_processing must be object']);
      exit;
    }
    save_post_processing($pp);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'save_paid_providers') {
    $providers = $data['providers'] ?? [];
    if (!is_array($providers)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'providers must be array']);
      exit;
    }
    save_paid_providers($providers);
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'get_api_responses') {
    $file = dirname(__DIR__) . '/storage/api_responses.json';
    $list = [];
    if (file_exists($file)) {
      $raw = file_get_contents($file);
      if ($raw) $list = json_decode($raw, true) ?: [];
    }
    echo json_encode(['ok' => true, 'responses' => $list], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  if ($action === 'get_logs') {
    $filterDate = isset($data['date']) && $data['date'] !== '' ? (string)$data['date'] : '';
    $rows    = [];
    $summary = ['cnt' => 0, 'total_cost' => 0.0, 'total_inp' => 0, 'total_out' => 0, 'total_cache_r' => 0];
    $db = get_sqlite_db();
    if ($db) {
      try {
        $where  = $filterDate !== '' ? ' WHERE date = ?' : '';
        $params = $filterDate !== '' ? [$filterDate] : [];
        $stmt = $db->prepare(
          "SELECT date, time, model, provider,
                  input_tokens as inp, output_tokens as out,
                  cache_write, cache_read, cost, duration, prompt_len,
                  web_search as web, cache_status, error
           FROM requests$where ORDER BY id DESC LIMIT 500"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sum  = $db->prepare(
          "SELECT COUNT(*) as cnt, SUM(cost) as tc, SUM(input_tokens) as ti,
                  SUM(output_tokens) as to2, SUM(cache_read) as tr2
           FROM requests$where"
        );
        $sum->execute($params);
        $s = $sum->fetch(PDO::FETCH_ASSOC);
        $summary = [
          'cnt'          => (int)($s['cnt']  ?? 0),
          'total_cost'   => (float)($s['tc']  ?? 0),
          'total_inp'    => (int)($s['ti']  ?? 0),
          'total_out'    => (int)($s['to2'] ?? 0),
          'total_cache_r'=> (int)($s['tr2'] ?? 0),
        ];
      } catch (Exception $e) { /* SQLite error — return empty */ }
    }
    echo json_encode(['ok' => true, 'rows' => $rows, 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  if ($action === 'get_history') {
    $db = get_sqlite_db();
    if (!$db) {
      http_response_code(503);
      echo json_encode(['ok' => false, 'error' => 'SQLite недоступний']);
      exit;
    }
    $page   = max(1, (int)($data['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    try {
      $rows  = $db->query(
        "SELECT id, created_at, model, provider, source_ref,
                substr(input_text, 1, 200) as input_preview,
                substr(output_json, 1, 500) as output_preview,
                cost, input_tokens, output_tokens, web_search_used
         FROM generations ORDER BY id DESC LIMIT $limit OFFSET $offset"
      )->fetchAll(PDO::FETCH_ASSOC);
      $total = (int)$db->query("SELECT COUNT(*) FROM generations")->fetchColumn();
      echo json_encode(['ok' => true, 'items' => $rows, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
  }

  if ($action === 'get_generation') {
    $db = get_sqlite_db();
    if (!$db) {
      http_response_code(503);
      echo json_encode(['ok' => false, 'error' => 'SQLite недоступний']);
      exit;
    }
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid id']);
      exit;
    }
    try {
      $stmt = $db->prepare("SELECT * FROM generations WHERE id = ?");
      $stmt->execute([$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      echo json_encode(['ok' => true, 'item' => $row ?: null], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
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
  'paid_providers' => get_paid_providers(),
  'post_processing' => get_post_processing(),
  'keys' => [
    'anthropic' => mask_val($keys['anthropic'] ?? ''),
    'xai' => mask_val($keys['xai'] ?? ''),
    'gemini' => mask_val($keys['gemini'] ?? ''),
    'mistral' => mask_val($keys['mistral'] ?? ''),
    'openai' => mask_val($keys['openai'] ?? ''),
    'deepseek' => mask_val($keys['deepseek'] ?? ''),
    'groq' => mask_val($keys['groq'] ?? ''),
  ],
  'default_model' => $models[0]['id'] ?? null,
  'prompt_system' => resolve_system_prompt($settings),
  'prompt_default_override' => (string)($settings['system_prompt_default_override'] ?? ''),
  'prompt_profiles' => $settings['prompt_profiles'] ?? get_default_prompt_profiles(),
  'prompt_profiles_default' => get_default_prompt_profiles(),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * Збереження prompts.json з автоматичним бекапом
 */
function save_prompts_to_json($prompts) {
    $promptsFile = dirname(__DIR__) . '/prompts.json';
    $json = json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    backup_prompts_file($promptsFile);
    return file_put_contents($promptsFile, $json, LOCK_EX) !== false;
}

function backup_prompts_file($promptsFile) {
    if (!file_exists($promptsFile)) return;
    $dir = dirname(__DIR__) . '/storage/prompt_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ts  = date('Ymd_His');
    @copy($promptsFile, "$dir/prompts_$ts.json");
    // зберігаємо тільки 5 останніх бекапів
    $files = glob("$dir/prompts_*.json") ?: [];
    if (count($files) > 5) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - 5) as $old) @unlink($old);
    }
}

function list_prompt_backups() {
    $dir   = dirname(__DIR__) . '/storage/prompt_backups';
    $files = glob("$dir/prompts_*.json") ?: [];
    rsort($files);
    $result = [];
    foreach ($files as $f) {
        $name = basename($f, '.json');
        if (!preg_match('/^prompts_(\d{8})_(\d{6})$/', $name, $m)) continue;
        $ts = mktime(
            (int)substr($m[2], 0, 2), (int)substr($m[2], 2, 2), (int)substr($m[2], 4, 2),
            (int)substr($m[1], 4, 2), (int)substr($m[1], 6, 2), (int)substr($m[1], 0, 4)
        );
        $result[] = ['name' => $name, 'ts' => $ts, 'label' => date('d.m.Y H:i:s', $ts)];
    }
    return $result;
}

function validate_prompt_profiles_payload($profiles) {
  if (!is_array($profiles)) return 'profiles must be object';
  $user = $profiles['user'] ?? null;
  if (!is_array($user)) return 'profiles.user must be object';

  $requiredStrings = [
    'json_rule', 'requirements_title', 'input_title',
    'news_fields_on', 'news_requirements_on',
    'tone_prefix', 'depth_prefix', 'source_ref_rule',
  ];
  foreach ($requiredStrings as $field) {
    $v = $user[$field] ?? '';
    if (!is_string($v) || trim($v) === '') return "profiles.user.$field is required and must be non-empty";
  }

  $tsr = $user['tone_short_rules'] ?? null;
  if (!is_array($tsr)) return 'profiles.user.tone_short_rules must be object';
  $toneKeys = ['neutral', 'intriguing', 'emotional', 'seo'];
  foreach ($toneKeys as $k) {
    if (!isset($tsr[$k]) || trim((string)$tsr[$k]) === '') return "profiles.user.tone_short_rules.$k is required";
  }

  return null;
}

function validate_prompts_json_payload($prompts) {
  if (!is_array($prompts)) return 'prompts must be object';

  $sp = $prompts['system_prompts'] ?? null;
  if (!is_array($sp)) return 'prompts.system_prompts must be object';
  $spDefault = $sp['default'] ?? '';
  if (!is_string($spDefault) || trim($spDefault) === '') return 'prompts.system_prompts.default must be non-empty string';

  $up = $prompts['user_prompt_profiles'] ?? null;
  if (!is_array($up)) return 'prompts.user_prompt_profiles must be object';
  $upDefault = $up['default'] ?? null;
  if (!is_array($upDefault)) return 'prompts.user_prompt_profiles.default must be object';

  return null;
}

function validate_models_payload($models) {
  $allowedProviders = PROVIDERS_ALL;
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
