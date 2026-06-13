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
define('SQLITE_DB_FILE', APP_ROOT . '/storage/requests.db');
define('PROVIDERS_ALL',        ['anthropic', 'xai', 'gemini', 'mistral', 'openai', 'deepseek', 'groq']);
define('PROVIDERS_OAI_COMPAT', ['xai', 'mistral', 'openai', 'deepseek', 'groq']);

$_tz = getenv('APP_TIMEZONE') ?: '';
if ($_tz === '') {
  // Read from .env.local before full env loading
  $_envFile = getenv('APP_ENV_FILE') ?: (dirname(__DIR__) . '/.env.local');
  if (is_readable($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
      if (str_starts_with(trim($_line), 'APP_TIMEZONE=')) {
        $_tz = trim(substr(trim($_line), strlen('APP_TIMEZONE=')));
        break;
      }
    }
  }
}
date_default_timezone_set(($_tz !== '' && @timezone_open($_tz)) ? $_tz : 'Europe/Kyiv');
unset($_tz, $_envFile, $_line);

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
                ['id' => 'claude-sonnet-4-6', 'label' => 'Sonnet 4.6', 'provider' => 'anthropic', 'inp' => 3.0, 'out' => 15.0]
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
    return $prompts['default_settings']['models'] ?? [];
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

function get_provider_env_map(): array {
  return [
    'anthropic' => 'ANTHROPIC_API_KEY',
    'xai'       => 'XAI_API_KEY',
    'gemini'    => 'GEMINI_API_KEY',
    'mistral'   => 'MISTRAL_API_KEY',
    'openai'    => 'OPENAI_API_KEY',
    'deepseek'  => 'DEEPSEEK_API_KEY',
    'groq'      => 'GROQ_API_KEY',
  ];
}

function get_runtime_keys(): array {
  $keys = [];
  foreach (get_provider_env_map() as $provider => $envName) {
    $keys[$provider] = env_str($envName, '');
  }
  return $keys;
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
      'provider' => in_array(($m['provider'] ?? ''), PROVIDERS_ALL, true) ? $m['provider'] : 'anthropic',
      'inp'        => (float)($m['inp'] ?? 3.0),
      'out'        => (float)($m['out'] ?? 15.0),
      'max_tokens' => isset($m['max_tokens']) ? max(256, min(32000, (int)$m['max_tokens'])) : 8000,
      'timeout'    => isset($m['timeout']) ? max(30, min(600, (int)$m['timeout'])) : 120,
      'enabled'    => isset($m['enabled']) ? (bool)$m['enabled'] : true,
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
  // OPcache може зберігати старий варіант файлу — інвалідуємо явно
  if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(SETTINGS_FILE, true);
  }
}

/**
 * Оновлює тільки передані поля settings, решту залишає незміненою.
 */
function update_settings(array $patch): void {
  $current = load_settings();
  save_settings(array_merge([
    'models'                         => $current['models'] ?? [],
    'system_prompt_custom'           => (string)($current['system_prompt_custom'] ?? ''),
    'system_prompt_default_override' => (string)($current['system_prompt_default_override'] ?? ''),
    'prompt_profiles'                => $current['prompt_profiles'] ?? get_default_prompt_profiles(),
  ], $patch));
}

/**
 * Збереження останніх N відповідей API у JSON-файл (для діагностики в адмінці).
 */
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

function mask_val($value) {
  $value = (string)$value;
  if ($value === '') return 'не задано';
  $len = strlen($value);
  if ($len <= 10) return str_repeat('*', $len);
  return substr($value, 0, 5) . str_repeat('*', max(0, $len - 10)) . substr($value, -5);
}

function get_paid_providers() {
  $file = APP_ROOT . '/storage/paid_providers.json';
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  if (!$raw) return [];
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];
  return array_values(array_filter($arr, function($v) { return in_array($v, PROVIDERS_ALL, true); }));
}

function save_paid_providers(array $providers) {
  $clean = array_values(array_filter($providers, function($v) { return in_array($v, PROVIDERS_ALL, true); }));
  $dir = APP_ROOT . '/storage';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  file_put_contents($dir . '/paid_providers.json', json_encode($clean), LOCK_EX);
}

define('POST_PROCESSING_QUOTE_STYLES', ['upper', 'guillemets', 'low_high', 'none']);

function get_post_processing() {
  $file = APP_ROOT . '/storage/post_processing.json';
  $defaults = ['quote_style' => 'upper'];
  if (!file_exists($file)) return $defaults;
  $raw = file_get_contents($file);
  if (!$raw) return $defaults;
  $data = json_decode($raw, true);
  if (!is_array($data)) return $defaults;
  if (!in_array($data['quote_style'] ?? '', POST_PROCESSING_QUOTE_STYLES, true)) {
    $data['quote_style'] = 'upper';
  }
  return $data;
}

function save_post_processing(array $data) {
  $clean = ['quote_style' => in_array($data['quote_style'] ?? '', POST_PROCESSING_QUOTE_STYLES, true) ? $data['quote_style'] : 'upper'];
  $dir = APP_ROOT . '/storage';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  file_put_contents($dir . '/post_processing.json', json_encode($clean), LOCK_EX);
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


function settings_model_map($settings) {
  $map = [];
  foreach (($settings['models'] ?? []) as $model) {
    $map[$model['id']] = $model;
  }
  return $map;
}

/**
 * Ініціалізація SQLite бази даних та повернення PDO-з'єднання (singleton).
 * Повертає null якщо PDO sqlite недоступний або виникла помилка.
 */
function get_sqlite_db() {
  static $pdo = null;
  static $failed = false;

  if ($failed) return null;
  if ($pdo !== null) return $pdo;

  if (!extension_loaded('pdo_sqlite')) {
    $failed = true;
    return null;
  }

  $dbFile = SQLITE_DB_FILE;
  $dbDir  = dirname($dbFile);
  if (!is_dir($dbDir)) {
    if (!@mkdir($dbDir, 0775, true)) {
      $failed = true;
      return null;
    }
  }
  if (!is_writable($dbDir)) {
    $failed = true;
    return null;
  }

  try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=5000');

    $pdo->exec('CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        date TEXT NOT NULL,
        time TEXT NOT NULL,
        model TEXT NOT NULL,
        provider TEXT NOT NULL,
        input_tokens INTEGER DEFAULT 0,
        output_tokens INTEGER DEFAULT 0,
        cache_write INTEGER DEFAULT 0,
        cache_read INTEGER DEFAULT 0,
        cost REAL DEFAULT 0,
        duration REAL DEFAULT 0,
        prompt_len INTEGER DEFAULT 0,
        web_search INTEGER DEFAULT 0,
        cache_status TEXT DEFAULT \'no-cache\',
        error TEXT DEFAULT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        model TEXT NOT NULL,
        provider TEXT NOT NULL,
        source_ref TEXT DEFAULT \'\',
        input_text TEXT DEFAULT \'\',
        extra_instructions TEXT DEFAULT \'\',
        output_json TEXT DEFAULT \'\',
        cost REAL DEFAULT 0,
        input_tokens INTEGER DEFAULT 0,
        output_tokens INTEGER DEFAULT 0,
        web_search_used INTEGER DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS async_jobs (
        id TEXT PRIMARY KEY,
        created_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        model TEXT NOT NULL DEFAULT \'\',
        provider TEXT NOT NULL DEFAULT \'\',
        prompt_text TEXT NOT NULL DEFAULT \'\',
        source_text TEXT NOT NULL DEFAULT \'\',
        source_ref TEXT NOT NULL DEFAULT \'\',
        system_prompt TEXT NOT NULL DEFAULT \'\',
        max_tokens INTEGER NOT NULL DEFAULT 8000,
        extra_instructions TEXT DEFAULT \'\',
        worker_pid INTEGER DEFAULT NULL,
        finished_at TEXT DEFAULT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS async_job_chunks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id TEXT NOT NULL,
        chunk TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_job_chunks ON async_job_chunks (job_id, id)');

    // Migrations: add columns that may be missing in older DB files
    $existingCols = array_column(
      $pdo->query("PRAGMA table_info(generations)")->fetchAll(PDO::FETCH_ASSOC),
      'name'
    );
    if (!in_array('web_search_used', $existingCols, true)) {
      $pdo->exec('ALTER TABLE generations ADD COLUMN web_search_used INTEGER DEFAULT 0');
    }
    if (!in_array('extra_instructions', $existingCols, true)) {
      $pdo->exec("ALTER TABLE generations ADD COLUMN extra_instructions TEXT DEFAULT ''");
    }

    $existingJobCols = array_column(
      $pdo->query("PRAGMA table_info(async_jobs)")->fetchAll(PDO::FETCH_ASSOC),
      'name'
    );
    if (!in_array('extra_instructions', $existingJobCols, true)) {
      $pdo->exec("ALTER TABLE async_jobs ADD COLUMN extra_instructions TEXT DEFAULT ''");
    }
  } catch (Exception $e) {
    error_log('SQLite init error: ' . $e->getMessage());
    $pdo    = null;
    $failed = true;
    return null;
  }

  return $pdo;
}

/**
 * Запис рядка запиту до SQLite
 */
function sqlite_log_request($payload) {
  $db = get_sqlite_db();
  if (!$db) return false;
  try {
    $stmt = $db->prepare(
      'INSERT INTO requests (created_at,date,time,model,provider,input_tokens,output_tokens,cache_write,cache_read,cost,duration,prompt_len,web_search,cache_status,error)
       VALUES (:created_at,:date,:time,:model,:provider,:input_tokens,:output_tokens,:cache_write,:cache_read,:cost,:duration,:prompt_len,:web_search,:cache_status,:error)'
    );
    $stmt->execute([
      ':created_at'    => date('c'),
      ':date'          => (string)($payload['date'] ?? date('Y-m-d')),
      ':time'          => (string)($payload['time'] ?? date('H:i:s')),
      ':model'         => (string)($payload['model'] ?? ''),
      ':provider'      => (string)($payload['provider'] ?? ''),
      ':input_tokens'  => (int)($payload['inp'] ?? 0),
      ':output_tokens' => (int)($payload['out'] ?? 0),
      ':cache_write'   => (int)($payload['cache_write'] ?? 0),
      ':cache_read'    => (int)($payload['cache_read'] ?? 0),
      ':cost'          => (float)($payload['cost'] ?? 0),
      ':duration'      => (float)($payload['duration'] ?? 0),
      ':prompt_len'    => (int)($payload['prompt_len'] ?? 0),
      ':web_search'    => !empty($payload['web']) ? 1 : 0,
      ':cache_status'  => (string)($payload['cache_status'] ?? 'no-cache'),
      ':error'         => isset($payload['error']) ? (string)$payload['error'] : null,
    ]);
    return true;
  } catch (Exception $e) {
    error_log('SQLite log_request error: ' . $e->getMessage());
    return false;
  }
}

/**
 * Збереження результату генерації до SQLite
 */
function save_generation_to_db($payload) {
  $db = get_sqlite_db();
  if (!$db) return false;
  try {
    $stmt = $db->prepare(
      'INSERT INTO generations (created_at,model,provider,source_ref,input_text,extra_instructions,output_json,cost,input_tokens,output_tokens,web_search_used)
       VALUES (:created_at,:model,:provider,:source_ref,:input_text,:extra_instructions,:output_json,:cost,:input_tokens,:output_tokens,:web_search_used)'
    );
    $stmt->execute([
      ':created_at'          => date('c'),
      ':model'               => (string)($payload['model'] ?? ''),
      ':provider'            => (string)($payload['provider'] ?? ''),
      ':source_ref'          => (string)($payload['source_ref'] ?? ''),
      ':input_text'          => (string)($payload['input_text'] ?? ''),
      ':extra_instructions'  => (string)($payload['extra_instructions'] ?? ''),
      ':output_json'         => (string)($payload['output_json'] ?? ''),
      ':cost'                => (float)($payload['cost'] ?? 0),
      ':input_tokens'        => (int)($payload['input_tokens'] ?? 0),
      ':output_tokens'       => (int)($payload['output_tokens'] ?? 0),
      ':web_search_used'     => (int)($payload['web_search_used'] ?? 0),
    ]);
    return true;
  } catch (Exception $e) {
    error_log('SQLite save_generation error: ' . $e->getMessage());
    return false;
  }
}
?>
