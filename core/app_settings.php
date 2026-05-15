<?php
/**
 * app_settings.php — централізоване керування налаштуваннями та безпекою.
 * Тепер усі промти завантажуються з prompts.json.
 */

define('APP_ROOT', dirname(__DIR__));
define('SETTINGS_FILE', APP_ROOT . '/settings_store.php');
define('DEFAULT_ENV_FILE', APP_ROOT . '/.env.local');
define('MAX_ENV_FILE_SIZE', 1024 * 1024); // 1MB safety cap
define('MAX_SETTINGS_FILE_SIZE', 1024 * 1024); // 1MB safety cap
define('PROMPTS_FILE', APP_ROOT . '/prompts.json');

date_default_timezone_set('UTC');

/**
 * Завантаження промтів з JSON-файлу
 */
function load_prompts_from_json() {
    if (!file_exists(PROMPTS_FILE) || !is_readable(PROMPTS_FILE)) {
        return get_fallback_prompts();
    }

    $json = file_get_contents(PROMPTS_FILE);
    if ($json === false) {
        return get_fallback_prompts();
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log("Prompts JSON decode error: " . json_last_error_msg());
        return get_fallback_prompts();
    }

    // Перевірка обов'язкових полів
    $error = validate_prompts($data);
    if ($error !== null) {
        error_log("Prompts validation error: " . $error);
        return get_fallback_prompts();
    }

    return $data;
}

/**
 * Резервні дефолтні промти (на випадок помилки)
 */
function get_fallback_prompts() {
    return [
        'system_prompts' => [
            'default' => 'Ти — AI-асистент для генерації новин українською мовою. Дотримуйся українського правопису.'
        ],
        'user_prompt_profiles' => [
            'default' => [
                'json_rule' => 'Поверни ВИКЛЮЧНО валідний JSON.',
                'requirements_title' => 'ВИМОГИ:',
                'news_fields_on' => '"headlines": [], "leads": [], "article": ""',
                'news_requirements_on' => 'Заголовки: {{headlines_count}} варіантів.',
                'fb_checkbox_on' => 'Facebook-допис: до {{facebook_max_chars}} символів.',
                'depth_prefix' => 'Глибина рерайту: {{depth_text}}.',
                'source_ref_rule' => 'Використай джерело: {{source_ref}}.',
                'websearch_on' => 'Web-пошук увімкнений.',
                'websearch_off' => 'Web-пошук вимкнений.',
                'input_title' => 'ВХІДНИЙ МАТЕРІАЛ:',
                'fb_style_rules' => [
                    'Серйозний стиль: стримано, без емодзі.',
                    'Нейтральний стиль: рівний тон.',
                    'Дружній стиль: теплий тон, помірні емодзі.',
                    'Гумористичний стиль: легкий доречний гумор.'
                ],
                'headlines_count' => 4,
                'leads_count' => 2,
                'article_max_chars' => 3000,
                'facebook_max_chars' => 400
            ]
        ],
        'default_settings' => [
            'models' => [
                ['id' => 'claude-sonnet-4-6', 'label' => 'Sonnet 4.6', 'provider' => 'anthropic', 'inp' => 3.0, 'out' => 15.0, 'web_search' => true]
            ],
            'cors_allowed_origins' => ['http://localhost']
        ]
    ];
}

/**
 * Валідація промтів
 */
function validate_prompts($prompts) {
    $requiredSystemFields = ['default'];
    $requiredUserFields = [
        'json_rule', 'requirements_title', 'news_fields_on',
        'news_requirements_on', 'depth_prefix'
    ];

    // Перевірка SYSTEM промтів
    foreach ($requiredSystemFields as $field) {
        if (empty($prompts['system_prompts'][$field])) {
            return "Відсутнє обов'язкове поле system_prompts.$field";
        }
    }

    // Перевірка USER промтів
    if (empty($prompts['user_prompt_profiles']['default'])) {
        return "Відсутнє обов'язкове поле user_prompt_profiles.default";
    }

    foreach ($requiredUserFields as $field) {
        if (empty($prompts['user_prompt_profiles']['default'][$field])) {
            return "Відсутнє обов'язкове поле user_prompt_profiles.default.$field";
        }
    }

    return null;
}

/**
 * Отримання SYSTEM промту за замовчуванням
 */
function get_default_system_prompt() {
    $prompts = load_prompts_from_json();
    return $prompts['system_prompts']['default'] ?? '';
}

/**
 * Отримання USER промтів за замовчуванням
 */
function get_default_prompt_profiles() {
  $prompts = load_prompts_from_json();
  $defaultProfile = $prompts['user_prompt_profiles']['default'] ?? [];
  return ['user' => $defaultProfile];  // Обгортаємо в ["user" => [...]]
}

/**
 * Отримання дефолтних налаштувань моделей
 */
function get_default_models() {
    $prompts = load_prompts_from_json();
    return $prompts['default_settings']['models'] ?? [
        ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5 — швидко / дешево', 'provider' => 'anthropic', 'inp' => 1.00, 'out' => 5.00, 'web_search' => true],
        ['id' => 'claude-sonnet-4-6', 'label' => 'Sonnet 4.6 — баланс / рекомендовано', 'provider' => 'anthropic', 'inp' => 3.00, 'out' => 15.00, 'web_search' => true],
    ];
}

/**
 * Отримання дефолтних CORS налаштувань
 */
function get_default_cors_origins() {
    $prompts = load_prompts_from_json();
    return $prompts['default_settings']['cors_allowed_origins'] ?? [];
}

function get_env_file_path() {
  $custom = getenv('APP_ENV_FILE');
  if ($custom !== false) {
    $custom = trim((string)$custom);
    if ($custom !== '') return $custom;
  }
  return DEFAULT_ENV_FILE;
}

function parse_env_file($path) {
  $out = [];
  if (!is_string($path) || $path === '' || !is_readable($path)) return $out;

  $size = @filesize($path);
  if (is_int($size) && $size > MAX_ENV_FILE_SIZE) return $out;

  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) return $out;

  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    if ($k === '') continue;
    $out[$k] = trim($v);
  }

  return $out;
}

function runtime_env_map() {
  static $cache = null;
  if ($cache !== null) return $cache;

  $cache = parse_env_file(get_env_file_path());
  return $cache;
}

function env_str($name, $default = '') {
  $name = (string)$name;
  if ($name === '') return $default;

  $value = getenv($name);
  if ($value !== false) {
    $value = trim((string)$value);
    if ($value !== '') return $value;
  }

  $fileEnv = runtime_env_map();
  if (isset($fileEnv[$name])) {
    $value = trim((string)$fileEnv[$name]);
    if ($value !== '') return $value;
  }

  return $default;
}

function env_list($name) {
  $raw = env_str($name, '');
  if ($raw === '') return [];
  $items = array_map('trim', explode(',', $raw));
  return array_values(array_filter($items, fn($v) => $v !== ''));
}

function apply_cors_headers() {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allowed = env_list('CORS_ALLOWED_ORIGINS');

  // Якщо CORS_ALLOWED_ORIGINS не встановлений, беремо дефолтні значення з prompts.json
  if (empty($allowed)) {
    $allowed = get_default_cors_origins();
  }

  if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
  }
}

function get_auth_password($envName, $fallback) {
  $pwd = env_str($envName, '');
  return $pwd !== '' ? $pwd : $fallback;
}

function get_runtime_keys() {
  return [
    'anthropic' => env_str('ANTHROPIC_API_KEY', ''),
    'xai' => env_str('XAI_API_KEY', ''),
    'gemini' => env_str('GEMINI_API_KEY', ''),
    'mistral' => env_str('MISTRAL_API_KEY', ''),
  ];
}

function get_default_settings() {
  return [
    'keys' => get_runtime_keys(),
    'models' => get_default_models(),
    'system_prompt_custom' => '',
    'system_prompt_default_override' => get_default_system_prompt(),
    'prompt_profiles' => get_default_prompt_profiles(),
  ];
}

function normalize_settings($settings) {
  $defaults = get_default_settings();
  if (!is_array($settings)) return $defaults;

  $models = $settings['models'] ?? [];
  if (!is_array($models)) $models = $defaults['models'];

  $normModels = [];
  foreach ($models as $m) {
    if (empty($m['id']) || empty($m['provider'])) continue;
    $normModels[] = [
      'id' => trim((string)$m['id']),
      'label' => trim((string)($m['label'] ?? $m['id'])),
      'provider' => in_array(($m['provider'] ?? ''), ['anthropic', 'xai', 'gemini', 'mistral'], true) ? $m['provider'] : 'anthropic',
      'inp' => (float)($m['inp'] ?? 3.0),
      'out' => (float)($m['out'] ?? 15.0),
      'web_search' => !empty($m['web_search']),
    ];
  }
  if ($models && !$normModels) $normModels = $defaults['models'];

  $profiles = $settings['prompt_profiles'] ?? get_default_prompt_profiles();

  // Виправлення: обгортаємо $profiles в ["user" => [...]], якщо потрібно
  if (is_array($profiles) && !isset($profiles['user'])) {
    $profiles = ['user' => $profiles];
  }

  // Переконайтеся, що $profiles має поле "user"
  if (!isset($profiles['user']) || !is_array($profiles['user'])) {
    $profiles['user'] = get_default_prompt_profiles()['user'];
  }

  return [
    'keys' => get_runtime_keys(),
    'models' => $normModels,
    'system_prompt_custom' => trim((string)($settings['system_prompt_custom'] ?? '')),
    'system_prompt_default_override' => trim((string)($settings['system_prompt_default_override'] ?? get_default_system_prompt())),
    'prompt_profiles' => $profiles,
  ];
}

function load_settings() {
  if (!file_exists(SETTINGS_FILE) || !is_readable(SETTINGS_FILE)) return get_default_settings();

  $size = @filesize(SETTINGS_FILE);
  if (is_int($size) && $size > MAX_SETTINGS_FILE_SIZE) return get_default_settings();

  $settings = @include SETTINGS_FILE;
  if (!is_array($settings)) return get_default_settings();

  return normalize_settings($settings);
}

function save_settings($settings) {
  $normalized = normalize_settings($settings);
  $stored = [
    'models' => $normalized['models'],
    'system_prompt_custom' => $normalized['system_prompt_custom'],
    'system_prompt_default_override' => $normalized['system_prompt_default_override'],
    'prompt_profiles' => $normalized['prompt_profiles'],
  ];
  $payload = "<?php\nreturn " . var_export($stored, true) . ";\n";
  file_put_contents(SETTINGS_FILE, $payload, LOCK_EX);
  @chmod(SETTINGS_FILE, 0600);
}

function resolve_system_prompt($settings) {
  return trim((string)($settings['system_prompt_default_override'] ?? $settings['system_prompt_custom'] ?? get_default_system_prompt()));
}

function save_env_values($pairs) {
  $path = get_env_file_path();
  $current = parse_env_file($path);

  foreach ($pairs as $k => $v) {
    $k = trim((string)$k);
    $v = trim((string)$v);
    if ($k === '' || $v === '') continue;
    $current[$k] = $v;
  }

  $lines = [];
  foreach ($current as $k => $v) {
    $lines[] = $k . '=' . $v;
  }

  $payload = implode("\n", $lines) . "\n";
  file_put_contents($path, $payload, LOCK_EX);
  @chmod($path, 0600);
}

function parse_log_entry($line) {
  $line = trim((string)$line);
  if ($line === '') return null;

  if (str_starts_with($line, '{')) {
    $obj = json_decode($line, true);
    if (!is_array($obj)) return null;
    return [
      'v' => (int)($obj['v'] ?? 2),
      'date' => (string)($obj['date'] ?? ''),
      'time' => (string)($obj['time'] ?? ''),
      'model' => (string)($obj['model'] ?? ''),
      'inp' => (int)($obj['inp'] ?? 0),
      'out' => (int)($obj['out'] ?? 0),
      'cache_write' => (int)($obj['cache_write'] ?? 0),
      'cache_read' => (int)($obj['cache_read'] ?? 0),
      'cost' => (float)($obj['cost'] ?? 0),
      'duration' => (string)($obj['duration'] ?? ''),
      'prompt_len' => (int)($obj['prompt_len'] ?? 0),
      'web' => !empty($obj['web']) ? 'web' : 'no-web',
      'cache_status' => (string)($obj['cache_status'] ?? 'no-cache'),
    ];
  }

  $r = str_getcsv($line);
  if (count($r) < 12) return null;
  return [
    'v' => 1,
    'date' => (string)($r[0] ?? ''),
    'time' => (string)($r[1] ?? ''),
    'model' => (string)($r[2] ?? ''),
    'inp' => (int)($r[3] ?? 0),
    'out' => (int)($r[4] ?? 0),
    'cache_write' => (int)($r[5] ?? 0),
    'cache_read' => (int)($r[6] ?? 0),
    'cost' => (float)($r[7] ?? 0),
    'duration' => (string)($r[8] ?? ''),
    'prompt_len' => (int)($r[9] ?? 0),
    'web' => (string)($r[10] ?? 'no-web'),
    'cache_status' => (string)($r[11] ?? 'no-cache'),
  ];
}

function build_log_entry_jsonl($payload) {
  $entry = [
    'v' => 2,
    'date' => (string)($payload['date'] ?? date('Y-m-d')),
    'time' => (string)($payload['time'] ?? date('H:i:s')),
    'model' => (string)($payload['model'] ?? ''),
    'inp' => (int)($payload['inp'] ?? 0),
    'out' => (int)($payload['out'] ?? 0),
    'cache_write' => (int)($payload['cache_write'] ?? 0),
    'cache_read' => (int)($payload['cache_read'] ?? 0),
    'cost' => (float)($payload['cost'] ?? 0),
    'duration' => (string)($payload['duration'] ?? ''),
    'prompt_len' => (int)($payload['prompt_len'] ?? 0),
    'web' => !empty($payload['web']),
    'cache_status' => (string)($payload['cache_status'] ?? 'no-cache'),
  ];
  return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function settings_model_map($settings) {
  $map = [];
  foreach (($settings['models'] ?? []) as $model) {
    $map[$model['id']] = $model;
  }
  return $map;
}
?>
