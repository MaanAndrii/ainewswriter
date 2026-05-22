<?php
require_once __DIR__ . '/../core/app_settings.php';
require_once __DIR__ . '/../version.php';


session_start();

$auth_error = false;
if (isset($_POST['pwd'])) {
  if ($_POST['pwd'] === get_auth_password('ADMIN_PASSWORD', 'change-me-now')) {
    $_SESSION['admin_auth'] = true;
    header('Location: /admin/admin.php');
    exit;
  } else {
    $auth_error = true;
  }
}
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: /');
  exit;
}

if (empty($_SESSION['admin_auth'])) {
?><!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>Адмін — вхід</title>
<link rel="stylesheet" href="/public/assets/fonts/fonts.css">
<style>
body { background:#f5f2eb; font-family:'Roboto',sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; }
.box { background:#fff; border:1px solid #d8d0be; border-radius:6px; padding:32px 40px; width:340px; }
h2 { font-family:'Roboto',sans-serif; margin-bottom:20px; color:#1a1714; }
input[type=password] { width:100%; padding:10px 13px; border:1px solid #d8d0be; border-radius:3px; font-size:14px; margin-bottom:12px; outline:none; }
input[type=password]:focus { border-color:#b5401a; }
button { width:100%; padding:11px; background:#b5401a; color:#fff; border:none; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; cursor:pointer; }
.err { color:#b5401a; font-size:13px; margin-bottom:10px; }
.hint { margin-top:10px; color:#8a8278; font-size:12px; }
</style></head><body><div class="box">
<h2>Панель адміністрування</h2>
<?php if ($auth_error): ?><p class="err">Невірний пароль</p><?php endif; ?>
<form id="loginForm" method="post" action="/admin/admin.php"><input type="password" name="pwd" placeholder="Пароль" autofocus><button type="submit" onclick="document.getElementById('loginForm').submit();return false;">Увійти</button></form>
<p class="hint">Пароль береться з env <code>ADMIN_PASSWORD</code>.</p>
</div></body></html><?php
  exit;
}

$settings = load_settings();
$msg = '';
$err = '';

// ── Системна інформація ──────────────────────────────────────────────────────
function collect_system_info() {
  $info = [];

  // Версія
  $info['version'] = 'V ' . APP_VERSION;

  // Git
  $root = APP_ROOT;
  $gitHash    = trim((string)@shell_exec("git -C " . escapeshellarg($root) . " log -1 --format='%h' 2>/dev/null"));
  $gitDate    = trim((string)@shell_exec("git -C " . escapeshellarg($root) . " log -1 --format='%ci' 2>/dev/null"));
  $gitSubject = trim((string)@shell_exec("git -C " . escapeshellarg($root) . " log -1 --format='%s' 2>/dev/null"));
  $gitBranch  = trim((string)@shell_exec("git -C " . escapeshellarg($root) . " rev-parse --abbrev-ref HEAD 2>/dev/null"));
  $info['git'] = [
    'hash'    => $gitHash ?: '—',
    'date'    => $gitDate ? date('d.m.Y H:i', strtotime($gitDate)) : '—',
    'subject' => $gitSubject ?: '—',
    'branch'  => $gitBranch ?: '—',
  ];

  // PHP
  $info['php'] = [
    'version'   => PHP_VERSION,
    'sapi'      => PHP_SAPI,
    'timezone'  => date_default_timezone_get(),
    'ext_curl'      => extension_loaded('curl'),
    'ext_sqlite'    => extension_loaded('pdo_sqlite'),
    'ext_mbstring'  => extension_loaded('mbstring'),
    'ext_opcache'   => extension_loaded('Zend OPcache') || extension_loaded('opcache'),
    'opcache_on'    => function_exists('opcache_get_status') && !empty(@opcache_get_status()['opcache_enabled']),
  ];

  // SQLite БД
  $dbFile = SQLITE_DB_FILE;
  $db = get_sqlite_db();
  $info['sqlite'] = [
    'available'    => $db !== null,
    'size'         => file_exists($dbFile) ? filesize($dbFile) : 0,
    'requests'     => 0,
    'generations'  => 0,
  ];
  if ($db) {
    try {
      $info['sqlite']['requests']    = (int)$db->query('SELECT COUNT(*) FROM requests')->fetchColumn();
      $info['sqlite']['generations'] = (int)$db->query('SELECT COUNT(*) FROM generations')->fetchColumn();
    } catch (Exception $e) {}
  }

  // Файли сховища
  $info['storage'] = [
    'dir_writable' => is_writable(APP_ROOT . '/storage'),
  ];

  // API-ключі (лише наявність)
  $keys = get_runtime_keys();
  $info['keys'] = [
    'anthropic' => !empty(trim((string)($keys['anthropic'] ?? ''))),
    'xai'       => !empty(trim((string)($keys['xai'] ?? ''))),
    'gemini'    => !empty(trim((string)($keys['gemini'] ?? ''))),
    'mistral'   => !empty(trim((string)($keys['mistral'] ?? ''))),
    'openai'    => !empty(trim((string)($keys['openai'] ?? ''))),
    'deepseek'  => !empty(trim((string)($keys['deepseek'] ?? ''))),
    'groq'      => !empty(trim((string)($keys['groq'] ?? ''))),
  ];

  // Моделі
  $si_settings = load_settings();
  $info['models_count'] = count($si_settings['models'] ?? []);

  return $info;
}

$sysinfo = collect_system_info();

$defaultPrompt = get_default_system_prompt();
$defaultOverride = $settings['system_prompt_default_override'] ?? '';
$runtimeKeys = get_runtime_keys();

function mask_val($value) {
  $value = (string)$value;
  if ($value === '') return 'не задано';
  $len = strlen($value);
  if ($len <= 10) return str_repeat('*', $len);
  return substr($value, 0, 5) . str_repeat('*', max(0, $len - 10)) . substr($value, -5);
}


function stats_from_sqlite() {
  $db = get_sqlite_db();
  if (!$db) return null;

  try {
    $out = [
      'total_requests' => 0,
      'total_cost' => 0.0,
      'total_inp' => 0,
      'total_out' => 0,
      'by_model' => [],
      'by_day' => [],
    ];

    $row = $db->query("SELECT COUNT(*), SUM(cost), SUM(input_tokens), SUM(output_tokens) FROM requests WHERE error IS NULL")->fetch(PDO::FETCH_NUM);
    if ($row) {
      $out['total_requests'] = (int)$row[0];
      $out['total_cost']     = (float)$row[1];
      $out['total_inp']      = (int)$row[2];
      $out['total_out']      = (int)$row[3];
    }

    $modelRows = $db->query("SELECT model, COUNT(*), SUM(cost) FROM requests WHERE error IS NULL GROUP BY model ORDER BY SUM(cost) DESC")->fetchAll(PDO::FETCH_NUM);
    foreach ($modelRows as $r) {
      $out['by_model'][$r[0]] = ['req' => (int)$r[1], 'cost' => (float)$r[2], 'inp' => 0, 'out' => 0];
    }

    $dayRows = $db->query("SELECT date, COUNT(*), SUM(cost) FROM requests WHERE error IS NULL GROUP BY date ORDER BY date DESC LIMIT 14")->fetchAll(PDO::FETCH_NUM);
    foreach ($dayRows as $r) {
      $out['by_day'][$r[0]] = ['req' => (int)$r[1], 'cost' => (float)$r[2]];
    }

    return $out;
  } catch (Exception $e) {
    return null;
  }
}

$stats = stats_from_sqlite() ?? ['total_requests' => 0, 'total_cost' => 0.0, 'total_inp' => 0, 'total_out' => 0, 'by_model' => [], 'by_day' => []];
$modelsJsonPretty = json_encode($settings['models'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$promptsFile = dirname(__DIR__) . '/prompts.json';
$promptsJsonPretty = file_exists($promptsFile) ? file_get_contents($promptsFile) : '{}';
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Адмін-панель</title>
<link rel="stylesheet" href="/public/assets/fonts/fonts.css">
<style>
*{box-sizing:border-box} body{margin:0;background:#f5f2eb;color:#1a1714;font-family:'Roboto',sans-serif}
.hdr{background:#1a1714;color:#f5f2eb;padding:14px 28px;border-bottom:3px solid #b5401a;display:flex;justify-content:space-between;align-items:center}
.hdr h1{margin:0;font-family:'Roboto',sans-serif;font-size:22px}.hdr a{color:#8a8278;text-decoration:none;font-family:'Roboto Mono',monospace;font-size:11px}
.hdr a:hover{color:#f5f2eb}.wrap{width:75%;margin:24px auto;padding:0 20px;display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
.card{background:#fff;border:1px solid #e8e2d4;border-radius:6px;padding:18px}.ttl{font-family:'Roboto Mono',monospace;font-size:11px;letter-spacing:.12em;color:#8a8278;text-transform:uppercase;margin-bottom:10px}
.lbl{display:block;margin:12px 0 6px;font-family:'Roboto Mono',monospace;font-size:10px;letter-spacing:.12em;color:#8a8278;text-transform:uppercase}
input[type=password],input[type=text],input[type=number],select,textarea{width:100%;border:1px solid #d8d0be;border-radius:4px;padding:10px 12px;font-size:13px;background:#fff} textarea{min-height:160px;resize:vertical;font-family:'Roboto Mono',monospace;line-height:1.5}
textarea.big{min-height:250px}.btn{margin-top:14px;background:#b5401a;color:#fff;border:0;border-radius:4px;padding:10px 16px;font-family:'Roboto Mono',monospace;font-size:11px;letter-spacing:.09em;text-transform:uppercase;cursor:pointer}
.small{font-size:12px;color:#8a8278}.ok{color:#2a5a30;font-size:13px;margin-top:8px}.err{color:#b5401a;font-size:13px;margin-top:8px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pill{display:inline-block;background:#faf8f3;border:1px solid #e8e2d4;padding:6px 9px;border-radius:4px;font-family:'Roboto Mono',monospace;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}th{font-family:'Roboto Mono',monospace;font-size:10px;color:#8a8278;text-transform:uppercase}
.model-grid{display:grid;grid-template-columns:2fr 1.3fr .8fr .8fr .8fr .8fr;gap:8px;align-items:end}
.btn-mini{background:#1a1714;color:#fff;border:0;border-radius:4px;padding:7px 10px;font-family:'Roboto Mono',monospace;font-size:10px;cursor:pointer}
.btn-mini.danger{background:#8e2d16}.btn-mini.muted{background:#8a8278}
tr.drag-over td{background:#f0ebe3;outline:2px dashed #b8a98a}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tab-btn{background:#fff;border:1px solid #d8d0be;border-radius:4px;padding:8px 12px;font-family:'Roboto Mono',monospace;font-size:11px;cursor:pointer}.tab-btn.active{background:#1a1714;color:#fff;border-color:#1a1714}.tab-pane{display:none}.tab-pane.active{display:block}
@media(max-width:980px){.wrap{grid-template-columns:1fr}}
.btn-icon{background:none;border:1px solid #d8d0be;border-radius:3px;padding:2px 7px;font-size:14px;cursor:pointer;line-height:1.2;color:#1a1714;transition:all .1s;vertical-align:middle}
.btn-icon:hover{background:#f5f2eb;border-color:#8a8278}
.btn-icon.danger{color:#8e2d16;border-color:#e8c0b8}.btn-icon.danger:hover{background:#fde8e8;border-color:#c08070}
.log-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.log-card{background:#fff;border:1px solid #e8e2d4;border-radius:4px;padding:12px 14px}
.log-card-lbl{font-family:'Roboto Mono',monospace;font-size:9px;letter-spacing:.15em;text-transform:uppercase;color:#8a8278;margin-bottom:4px}
.log-card-val{font-size:18px;font-weight:600;color:#1a1714}.log-card-val.red{color:#b5401a}.log-card-val.green{color:#2a5a30}
.log-filters{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.log-filters input[type=date]{padding:6px 10px;border:1px solid #d8d0be;border-radius:3px;font-family:'Roboto Mono',monospace;font-size:11px;outline:none;background:#fff}
.log-filters input[type=date]:focus{border-color:#b5401a}
.log-row{cursor:pointer}.log-row:hover td{background:#fff8f5 !important}.log-row.expanded td{background:#fff8f5 !important}
.row-detail{display:none;background:#faf5f0}.row-detail.open{display:table-row}
.row-detail td{padding:12px 14px;border-bottom:2px solid #e8e2d4}
.detail-grid{display:grid;grid-template-columns:130px 1fr;gap:4px 10px;font-size:12px}
.detail-key{font-family:'Roboto Mono',monospace;font-size:10px;color:#8a8278;text-transform:uppercase;letter-spacing:.08em;padding-top:2px}
.detail-val{font-family:'Roboto Mono',monospace;font-size:11px;word-break:break-all}
.log-tag{display:inline-block;padding:2px 6px;border-radius:3px;font-family:'Roboto Mono',monospace;font-size:9px;font-weight:500}
.tag-web{background:#e8f0fd;color:#3a5a9a}.tag-noweb{background:#f5f2eb;color:#8a8278}
.tag-hit{background:#eaf3eb;color:#2a5a30}.tag-write{background:#fff8e8;color:#8a6a20}.tag-nocache{background:#f5f2eb;color:#8a8278}
.tag-err{background:#fde8e8;color:#8e2d16}.tag-ok{background:#eaf3eb;color:#2a5a30}
.api-entry{border:1px solid #e8e2d4;border-radius:3px;margin-bottom:8px;overflow:hidden}
.api-entry-hdr{display:flex;gap:10px;align-items:center;padding:9px 12px;cursor:pointer;background:#faf8f3}
.api-entry-hdr:hover{background:#f5f0e8}
.api-entry-body{display:none;padding:10px 12px;background:#fff;border-top:1px solid #e8e2d4}
.api-entry-body.open{display:block}
.api-entry-body pre{font-family:'Roboto Mono',monospace;font-size:10px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto}
.site-footer{background:#1a1714;color:#6a6460;font-family:'Roboto Mono',monospace;font-size:10px;letter-spacing:.12em;text-align:center;padding:10px 24px;border-top:2px solid #b5401a}.site-footer a{color:#6a6460;text-decoration:none}.site-footer a:hover{color:#f5f2eb}
</style>
</head>
<body>
<header class="hdr"><h1>Адмін-панель проєкту</h1><div style="display:flex;gap:14px"><a href="?logout=1">✕ Вийти</a></div></header>
<div class="wrap">
  <div class="tabs" style="grid-column:1 / -1">
    <button type="button" class="tab-btn active" data-tab="ai">Налаштування AI</button>
    <button type="button" class="tab-btn" data-tab="prompts">Промти і параметри</button>
    <button type="button" class="tab-btn" data-tab="stats">Статистика</button>
    <button type="button" class="tab-btn" data-tab="security">Безпека</button>
    <button type="button" class="tab-btn" data-tab="logs">Логи</button>
    <button type="button" class="tab-btn" data-tab="io">Імпорт / Експорт</button>
    <button type="button" class="tab-btn" data-tab="system">Система</button>
  </div>

  <section class="tab-pane active" data-pane="ai">
    <div class="card">
      <div class="ttl">Налаштування AI</div>
      <form onsubmit="return false;">
        <label class="lbl" style="display:flex;align-items:center;gap:8px;cursor:pointer" id="keys_toggle">
          API ключі
          <span id="keys_toggle_icon" style="display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;background:#e8e3dc;border-radius:4px;font-size:14px;font-weight:700;color:#5a544c;user-select:none">+</span>
        </label>
        <div id="keys_section" style="display:none">
        <table id="keys_table">
          <tr><th>Provider</th><th>API ключ</th><th>Дія</th></tr>
          <tr><td>anthropic</td><td><input type="password" id="k_anthropic" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['anthropic'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['anthropic'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="anthropic">Зберегти</button></td></tr>
          <tr><td>xai</td><td><input type="password" id="k_xai" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['xai'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['xai'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="xai">Зберегти</button></td></tr>
          <tr><td>gemini</td><td><input type="password" id="k_gemini" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['gemini'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['gemini'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="gemini">Зберегти</button></td></tr>
          <tr><td>mistral</td><td><input type="password" id="k_mistral" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['mistral'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['mistral'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="mistral">Зберегти</button></td></tr>
          <tr><td>openai</td><td><input type="password" id="k_openai" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['openai'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['openai'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="openai">Зберегти</button></td></tr>
          <tr><td>deepseek</td><td><input type="password" id="k_deepseek" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['deepseek'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['deepseek'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="deepseek">Зберегти</button></td></tr>
          <tr><td>groq</td><td><input type="password" id="k_groq" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['groq'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['groq'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="groq">Зберегти</button></td></tr>
        </table>
        <div class="small" id="keys_status" style="margin-top:6px"></div>
        <div class="small" style="margin-bottom:10px">Ключі записуються у файл env: <code><?= htmlspecialchars(get_env_file_path()) ?></code>. Збереження ключа відбувається одразу по кнопці в таблиці.</div>
        </div>

        <label class="lbl">Моделі AI</label>
        <div class="model-grid">
          <div><label class="small">ID</label><input type="text" id="m_id" placeholder="claude-sonnet-4-6"></div>
          <div><label class="small">Назва</label><input type="text" id="m_label" placeholder="Sonnet 4.6"></div>
          <div><label class="small">Provider</label><select id="m_provider"><?php foreach (PROVIDERS_ALL as $p) echo '<option value="'.htmlspecialchars($p).'">'.htmlspecialchars($p).'</option>'; ?></select></div>
          <div><label class="small">Inp $/1M</label><input type="number" id="m_inp" step="0.01" value="3.00"></div>
          <div><label class="small">Out $/1M</label><input type="number" id="m_out" step="0.01" value="15.00"></div>
          <div><label class="small">Max tokens</label><input type="number" id="m_max_tokens" step="256" min="256" max="32000" value="8000"></div>
        </div>
        <div class="row" style="margin-top:8px">
          <div></div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button type="button" class="btn-mini muted" id="m_cancel" style="display:none">Скасувати редагування</button>
            <button type="button" class="btn-mini" id="m_add">Додати модель</button>
          </div>
        </div>
        <table id="models_table" style="margin-top:10px">
          <tr><th>Порядок</th><th>ID</th><th>Назва</th><th>Provider</th><th>Inp</th><th>Out</th><th>Max tok</th><th>Вкл</th><th>Дії</th></tr>
          <tbody></tbody>
        </table>
        <div class="small" id="models_status" style="margin-top:6px"></div>
        <textarea class="big" id="models_json" name="models_json" style="display:none"><?= htmlspecialchars($modelsJsonPretty) ?></textarea>
        <div class="small">Моделі зберігаються у вигляді JSON автоматично. Можна додавати, редагувати та видаляти у таблиці.</div>
      </form>
    </div>
  </section>

  <section class="tab-pane" data-pane="prompts">
    <?php
      $pp = $settings['prompt_profiles']['user'] ?? get_default_prompt_profiles()['user'];
      function pp_str($pp, $key, $def='') { return htmlspecialchars((string)($pp[$key] ?? $def)); }
      function pp_arr($pp, $key) { $v = $pp[$key] ?? []; return htmlspecialchars(implode("\n---\n", (array)$v)); }
      function pp_tone($pp) {
        $m = $pp['tone_short_rules'] ?? []; $out = [];
        foreach (['neutral','intriguing','emotional','seo'] as $k) $out[] = ($m[$k] ?? '');
        return htmlspecialchars(implode("\n---\n", $out));
      }
      function pp_fb($pp) {
        $a = $pp['fb_style_rules'] ?? []; $out = [];
        foreach ($a as $i => $v) $out[] = $v;
        return htmlspecialchars(implode("\n---\n", $out));
      }
    ?>

    <div class="card">
      <div class="ttl">System prompt</div>
      <textarea id="system_default_override" class="big" style="min-height:180px"><?= htmlspecialchars($defaultOverride !== '' ? $defaultOverride : $defaultPrompt) ?></textarea>
      <div class="small" style="margin-top:4px">Базові інструкції для моделі — роль редактора, мовні вимоги, формат відповіді.</div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap">
        <button type="button" class="btn-mini muted" id="restore_prompts_defaults_btn">Відновити за замовчуванням</button>
        <button type="button" class="btn-mini" id="save_as_default_btn" title="Зберегти поточний промт та параметри як нові значення за замовчуванням (prompts.json)">&#9733; Зберегти як за замовчуванням</button>
        <button type="button" class="btn-mini danger" id="save_system_default_btn">Зберегти system prompt</button>
      </div>
      <div class="small" id="save_system_status" style="text-align:right;margin-top:4px"></div>
    </div>

    <div class="card" style="margin-top:14px">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div class="ttl" style="margin-bottom:0">Резервні копії prompts.json</div>
        <button type="button" class="btn-mini muted" id="backups_reload_btn">&#8635; Оновити</button>
      </div>
      <div id="backups_list" style="margin-top:10px;font-family:'Roboto Mono',monospace;font-size:11px;color:#8a8278">Завантаження…</div>
      <div class="small" id="backup_restore_status" style="margin-top:6px"></div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="ttl">Параметри генерації</div>
      <div class="row">
        <div><label class="small">К-сть заголовків</label><input type="number" id="lim_headlines" min="1" max="10" value="<?= (int)($pp['headlines_count'] ?? 4) ?>"></div>
        <div><label class="small">К-сть лідів</label><input type="number" id="lim_leads" min="1" max="5" value="<?= (int)($pp['leads_count'] ?? 2) ?>"></div>
        <div><label class="small">Макс. символів новини</label><input type="number" id="lim_article" min="300" max="10000" value="<?= (int)($pp['article_max_chars'] ?? 3000) ?>"></div>
        <div><label class="small">Макс. символів Facebook</label><input type="number" id="lim_fb" min="50" max="2000" value="<?= (int)($pp['facebook_max_chars'] ?? 400) ?>"></div>
      </div>
      <div class="row" style="margin-top:8px">
        <div><label class="small">Мін. символів ліду</label><input type="number" id="lim_lead_min" min="50" max="500" value="<?= (int)($pp['lead_min_chars'] ?? 150) ?>"></div>
        <div><label class="small">Макс. символів ліду</label><input type="number" id="lim_lead_max" min="50" max="500" value="<?= (int)($pp['lead_max_chars'] ?? 180) ?>"></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
        <button type="button" class="btn-mini" id="save_prompt_limits_btn">Зберегти параметри</button>
      </div>
      <div class="small" id="save_limits_status" style="text-align:right;margin-top:4px"></div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="ttl">Складові user-промту</div>
      <div class="small" style="margin-bottom:12px">Кожне поле — це шматок тексту який підставляється в промт при генерації. Поля позначені <span style="color:#A32D2D;font-weight:500">*</span> є обов'язковими.</div>

      <label class="lbl">JSON-правило <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— перший рядок user-промту, вимога повернути JSON</span></label>
      <textarea id="pf_json_rule" rows="2" style="font-family:var(--font-mono, monospace);font-size:12px"><?= pp_str($pp,'json_rule') ?></textarea>

      <label class="lbl" style="margin-top:10px">Заголовок блоку параметрів <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— напр. «ПАРАМЕТРИ ЦЬОГО ЗАПУСКУ:»</span></label>
      <input type="text" id="pf_requirements_title" value="<?= pp_str($pp,'requirements_title') ?>">

      <label class="lbl" style="margin-top:10px">Заголовок вхідного матеріалу <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— напр. «ВХІДНИЙ МАТЕРІАЛ:»</span></label>
      <input type="text" id="pf_input_title" value="<?= pp_str($pp,'input_title','ВХІДНИЙ МАТЕРІАЛ:') ?>">

      <label class="lbl" style="margin-top:10px">Поля JSON при увімкненій новині <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— рядки всередині {}</span></label>
      <textarea id="pf_news_fields_on" rows="3" style="font-family:var(--font-mono, monospace);font-size:12px"><?= pp_str($pp,'news_fields_on') ?></textarea>

      <label class="lbl" style="margin-top:10px">Вимоги до новини <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— підтримує {{headlines_count}}, {{leads_count}}, {{lead_min_chars}}, {{lead_max_chars}}, {{article_max_chars}}, {{tone_label}}</span></label>
      <textarea id="pf_news_requirements_on" rows="2"><?= pp_str($pp,'news_requirements_on') ?></textarea>

      <label class="lbl" style="margin-top:10px">Префікс тональності <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— підтримує {{tone_label}}, {{tone_short}}</span></label>
      <input type="text" id="pf_tone_prefix" value="<?= pp_str($pp,'tone_prefix') ?>">

      <label class="lbl" style="margin-top:10px">Короткі описи тональностей <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— 4 записи розділені <code>---</code>: нейтральний, інтригуючий, емоційний, SEO</span></label>
      <textarea id="pf_tone_short_rules" rows="4" style="font-family:var(--font-mono, monospace);font-size:12px"><?= pp_tone($pp) ?></textarea>

      <label class="lbl" style="margin-top:10px">Префікс глибини рерайту <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— підтримує {{depth_text}}, {{depth_short}}</span></label>
      <input type="text" id="pf_depth_prefix" value="<?= pp_str($pp,'depth_prefix') ?>">


      <label class="lbl" style="margin-top:10px">Короткі інструкції глибини (0–3) <span class="small" style="font-weight:400">— 4 записи розділені <code>---</code>, підставляється в {{depth_short}}</span></label>
      <textarea id="pf_depth_short_rules" rows="4" style="font-family:var(--font-mono, monospace);font-size:12px"><?= pp_arr($pp,'depth_short_rules') ?></textarea>

      <label class="lbl" style="margin-top:10px">Інструкція для джерела <span class="small" style="font-weight:400">— {{source_ref}} замінюється значенням з поля «Джерело новини»; опишіть як органічно вписати джерело в перший абзац</span></label>
      <textarea id="pf_source_ref_rule" rows="3"><?= pp_str($pp,'source_ref_rule') ?></textarea>

      <label class="lbl" style="margin-top:10px">Facebook-рядок (увімкнено) <span class="small" style="font-weight:400">— підтримує {{facebook_max_chars}}, {{fb_style_rule}}</span></label>
      <textarea id="pf_fb_checkbox_on" rows="2"><?= pp_str($pp,'fb_checkbox_on') ?></textarea>

      <label class="lbl" style="margin-top:10px">Стилі Facebook (0–3) <span class="small" style="font-weight:400">— 4 записи розділені <code>---</code>, для повзунка серйозний→гумористичний</span></label>
      <textarea id="pf_fb_style_rules" rows="4" style="font-family:var(--font-mono, monospace);font-size:12px"><?= pp_fb($pp) ?></textarea>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn-mini danger" id="save_prompt_fields_btn">Зберегти складові промту</button>
      </div>
      <div class="small" id="save_fields_status" style="text-align:right;margin-top:4px"></div>
    </div>
  </section>

  <section class="tab-pane" data-pane="security">
    <div class="card">
      <div class="ttl">Безпека</div>
      <label class="lbl">Паролі доступу</label>
      <table>
        <tr><th>Зона</th><th>Новий пароль</th><th>Дія</th></tr>
        <tr><td>Адмін-панель</td><td><input type="password" id="pwd_admin" placeholder="мінімум 8 символів"></td><td><button type="button" class="btn-mini" data-save-password="admin">Зберегти</button></td></tr>
        <tr><td>Перегляд логів</td><td><input type="password" id="pwd_logs" placeholder="мінімум 8 символів"></td><td><button type="button" class="btn-mini" data-save-password="logs">Зберегти</button></td></tr>
      </table>
      <div class="small" id="password_status" style="margin-top:6px"></div>
    </div>
  </section>

  <section class="tab-pane" data-pane="logs">
    <div id="log-cards" class="log-cards"></div>
    <div class="log-filters">
      <input type="date" id="log-date">
      <button class="btn-mini" id="log-filter-btn" style="padding:7px 14px">Фільтрувати</button>
      <button class="btn-mini muted" id="log-clear-btn">Скинути</button>
      <button class="btn-mini" id="log-reload-btn" style="margin-left:auto">&#8635; Оновити</button>
    </div>
    <div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
      <div id="log-table-wrap" style="overflow-x:auto">
        <div style="padding:28px;text-align:center;font-family:Roboto Mono,monospace;font-size:11px;color:#8a8278">Перейдіть на вкладку «Логи» для завантаження</div>
      </div>
    </div>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span class="ttl" style="margin-bottom:0">Останні API відповіді (сирий формат)</span>
        <button class="btn-mini" id="btn_load_api">Завантажити</button>
      </div>
      <div id="api_responses_list" style="font-family:'Roboto Mono',monospace;font-size:11px;color:#8a8278">Натисніть «Завантажити» для перегляду</div>
    </div>
  </section>

  <aside class="tab-pane" data-pane="stats">
    <div class="card">
      <div class="ttl">Загальна статистика</div>
      <table>
        <tr><th>Метрика</th><th>Значення</th></tr>
        <tr><td>Запитів</td><td><?= number_format($stats['total_requests'], 0, '.', ' ') ?></td></tr>
        <tr><td>Вартість</td><td>$<?= number_format($stats['total_cost'], 4, '.', '') ?></td></tr>
        <tr><td>Вхід токенів</td><td><?= number_format($stats['total_inp'], 0, '.', ' ') ?></td></tr>
        <tr><td>Вихід токенів</td><td><?= number_format($stats['total_out'], 0, '.', ' ') ?></td></tr>
      </table>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="ttl">По моделях</div>
      <table>
        <tr><th>Модель</th><th>Req</th><th>Cost</th></tr>
        <?php foreach ($stats['by_model'] as $model => $r): ?>
          <tr>
            <td style="font-family:'Roboto Mono',monospace"><?= htmlspecialchars($model) ?></td>
            <td><?= (int)$r['req'] ?></td>
            <td>$<?= number_format((float)$r['cost'], 4, '.', '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="ttl">Останні дні</div>
      <table>
        <tr><th>Дата</th><th>Req</th><th>Cost</th></tr>
        <?php $i = 0; foreach ($stats['by_day'] as $date => $r): if ($i++ >= 14) break; ?>
          <tr>
            <td><?= htmlspecialchars($date) ?></td>
            <td><?= (int)$r['req'] ?></td>
            <td>$<?= number_format((float)$r['cost'], 4, '.', '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </aside>

  <section class="tab-pane" data-pane="io">
    <div class="card">
      <div class="ttl">Експорт налаштувань</div>
      <p class="small" style="margin-bottom:12px">Завантажує JSON-файл з моделями, промтами та параметрами генерації. <strong>API-ключі не включаються.</strong></p>
      <button type="button" class="btn" id="btn_export">⬇ Завантажити backup.json</button>
      <div class="small" id="export_status" style="margin-top:8px"></div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="ttl">Імпорт налаштувань</div>
      <p class="small" style="margin-bottom:12px">Оберіть раніше збережений <code>backup.json</code>. Можна вибірково відновити лише потрібні розділи.</p>

      <label class="lbl">Файл backup.json</label>
      <input type="file" id="import_file" accept=".json" style="margin-bottom:12px">

      <div id="import_preview" style="display:none">
        <label class="lbl">Що є у файлі</label>
        <div id="import_summary" style="font-size:12px;margin-bottom:10px"></div>

        <label class="lbl">Що імпортувати</label>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px">
          <label style="font-size:13px"><input type="checkbox" id="imp_models" checked> Моделі AI (<span id="imp_models_count">0</span> шт.)</label>
          <label style="font-size:13px"><input type="checkbox" id="imp_profiles" checked> Параметри промтів та профілі</label>
          <label style="font-size:13px"><input type="checkbox" id="imp_system" checked> System prompt (override)</label>
          <label style="font-size:13px"><input type="checkbox" id="imp_prompts_json" checked> prompts.json (системні шаблони)</label>
          <label style="font-size:13px"><input type="checkbox" id="imp_api_keys" checked> API-ключі (anthropic, xai, gemini, mistral, openai, deepseek, groq)</label>
        </div>

        <button type="button" class="btn-mini danger" id="btn_import_confirm">Застосувати імпорт</button>
      </div>

      <div class="small" id="import_status" style="margin-top:8px"></div>
    </div>
  </section>

  <section class="tab-pane" data-pane="system">
    <?php
    $si = $sysinfo;
    function fmt_bytes($b) {
      if ($b <= 0) return '0 Б';
      if ($b < 1024) return $b . ' Б';
      if ($b < 1048576) return round($b/1024, 1) . ' КБ';
      return round($b/1048576, 2) . ' МБ';
    }
    function yn($v, $yes='✓ так', $no='✗ ні') { return $v ? "<span style='color:#2a5a30'>$yes</span>" : "<span style='color:#b5401a'>$no</span>"; }
    ?>

    <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px;margin-bottom:14px">
      <div>
        <div style="font-size:28px;font-family:'Roboto Mono',monospace;font-weight:700;color:#1a1714;letter-spacing:-.02em"><?= htmlspecialchars($si['version']) ?></div>
        <div style="font-size:11px;color:#8a8278;font-family:'Roboto Mono',monospace;margin-top:4px">AI Newswriter</div>
      </div>
      <div style="text-align:right;font-size:12px;color:#8a8278;font-family:'Roboto Mono',monospace;line-height:1.8">
        <div>Гілка: <strong style="color:#1a1714"><?= htmlspecialchars($si['git']['branch']) ?></strong></div>
        <div>Коміт: <strong style="color:#1a1714"><?= htmlspecialchars($si['git']['hash']) ?></strong></div>
        <div>Дата: <strong style="color:#1a1714"><?= htmlspecialchars($si['git']['date']) ?></strong></div>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="ttl">Останній коміт</div>
      <div style="font-size:13px;color:#3a3530;font-family:'Roboto Mono',monospace;padding:4px 0"><?= htmlspecialchars($si['git']['subject']) ?></div>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="ttl">PHP і розширення</div>
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr><td style="padding:5px 0;color:#8a8278;width:55%">PHP версія</td><td><strong><?= htmlspecialchars($si['php']['version']) ?></strong> (<?= htmlspecialchars($si['php']['sapi']) ?>)</td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Часовий пояс</td><td><strong><?= htmlspecialchars($si['php']['timezone']) ?></strong></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">php-curl</td><td><?= yn($si['php']['ext_curl']) ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">php-sqlite3 (PDO)</td><td><?= yn($si['php']['ext_sqlite']) ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">php-mbstring</td><td><?= yn($si['php']['ext_mbstring'], '✓ так', '— fallback на substr') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">OPCache</td><td><?= yn($si['php']['opcache_on'], '✓ активний', '✗ вимкнено') ?></td></tr>
      </table>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="ttl">База даних SQLite</div>
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr><td style="padding:5px 0;color:#8a8278;width:55%">Доступність</td><td><?= yn($si['sqlite']['available'], '✓ працює', '✗ недоступна (fallback на JSONL)') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Розмір файлу</td><td><?= fmt_bytes($si['sqlite']['size']) ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Записів у логах</td><td><?= number_format($si['sqlite']['requests'], 0, '.', ' ') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Збережених генерацій</td><td><?= number_format($si['sqlite']['generations'], 0, '.', ' ') ?></td></tr>
      </table>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="ttl">Сховище</div>
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr><td style="padding:5px 0;color:#8a8278;width:55%">Папка storage/</td><td><?= yn($si['storage']['dir_writable'], '✓ доступна для запису', '✗ немає прав запису') ?></td></tr>
      </table>
    </div>

    <div class="card" style="margin-bottom:14px">
      <div class="ttl">API-ключі</div>
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr><td style="padding:5px 0;color:#8a8278;width:55%">Anthropic (Claude)</td><td><?= yn($si['keys']['anthropic'], '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">xAI (Grok)</td><td><?= yn($si['keys']['xai'], '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Google Gemini</td><td><?= yn($si['keys']['gemini'], '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Mistral</td><td><?= yn($si['keys']['mistral'], '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">OpenAI</td><td><?= yn($si['keys']['openai'] ?? false, '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">DeepSeek</td><td><?= yn($si['keys']['deepseek'] ?? false, '✓ задано', '✗ не задано') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">Groq</td><td><?= yn($si['keys']['groq'] ?? false, '✓ задано', '✗ не задано') ?></td></tr>
      </table>
    </div>

    <div class="card">
      <div class="ttl">Моделі та налаштування</div>
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr><td style="padding:5px 0;color:#8a8278;width:55%">Активних моделей</td><td><?= $si['models_count'] ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">settings_store.php</td><td><?= yn(file_exists(APP_ROOT . '/settings_store.php'), '✓ є (локальні налаштування)', '— немає (дефолти з prompts.json)') ?></td></tr>
        <tr><td style="padding:5px 0;color:#8a8278">.env.local</td><td><?= yn(file_exists(APP_ROOT . '/.env.local'), '✓ є', '✗ немає') ?></td></tr>
      </table>
    </div>
  </section>

</div>
<script>
var ALLOWED_PROVIDERS = <?= json_encode(PROVIDERS_ALL) ?>;
(function(){
  var models = <?= json_encode($settings['models'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var editIndex = -1;
  var jsonEl = document.getElementById('models_json');
  var tbody = document.querySelector('#models_table tbody');

  function esc(s){ return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function readForm(){
    return {
      id: document.getElementById('m_id').value.trim(),
      label: document.getElementById('m_label').value.trim(),
      provider: document.getElementById('m_provider').value,
      inp:        Number(document.getElementById('m_inp').value || 0),
      out:        Number(document.getElementById('m_out').value || 0),
      max_tokens: Math.max(256, Math.min(32000, parseInt(document.getElementById('m_max_tokens').value || 8000, 10))),
      enabled: editIndex >= 0 && models[editIndex] ? (models[editIndex].enabled !== false) : true
    };
  }
  function clearForm(){
    document.getElementById('m_id').value = '';
    document.getElementById('m_label').value = '';
    document.getElementById('m_provider').value = 'anthropic';
    document.getElementById('m_inp').value = '3.00';
    document.getElementById('m_out').value = '15.00';
    document.getElementById('m_max_tokens').value = '8000';
    editIndex = -1;
    document.getElementById('m_add').textContent = 'Додати модель';
    document.getElementById('m_cancel').style.display = 'none';
  }

  function dedupeModels(list){
    var seen = {};
    var out = [];
    for (var i=0;i<list.length;i++){
      var m = list[i] || {};
      var id = String(m.id || '').trim();
      if (!id || seen[id]) continue;
      seen[id] = 1;
      out.push(m);
    }
    return out;
  }

  function syncJson(){ jsonEl.value = JSON.stringify(models, null, 2); }
  function render(){
    var html = '';
    for (var i=0;i<models.length;i++){
      var m = models[i];
      var enabled = (m.enabled !== false);
      html += '<tr draggable="true" data-row="'+i+'" style="opacity:'+(enabled?'1':'0.45')+'">'
        + '<td style="font-family:Roboto Mono,monospace;cursor:grab" class="drag-handle">☰</td>'
        + '<td style="font-family:Roboto Mono,monospace">'+esc(m.id)+'</td>'
        + '<td>'+esc(m.label)+'</td>'
        + '<td>'+esc(m.provider)+'</td>'
        + '<td>'+Number(m.inp).toFixed(2)+'</td>'
        + '<td>'+Number(m.out).toFixed(2)+'</td>'
        + '<td>'+(m.max_tokens||8000)+'</td>'
        + '<td style="text-align:center"><input type="checkbox" '+(enabled?'checked':'')+' data-toggle="'+i+'" title="Вмикати/вимикати модель"></td>'
        + '<td style="white-space:nowrap"><button type="button" class="btn-icon" title="Редагувати" data-edit="'+i+'">✏</button> <button type="button" class="btn-icon danger" title="Видалити" data-del="'+i+'">✕</button></td>'
        + '</tr>';
    }
    tbody.innerHTML = html;
    syncJson();
  }

  function apiPost(payload, cb){
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/settings', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function(){
      var d = {};
      try { d = JSON.parse(xhr.responseText || '{}'); } catch(e) {}
      if (xhr.status !== 200 || d.ok === false) return cb(new Error(d.error || ('HTTP ' + xhr.status)));
      cb(null, d);
    };
    xhr.onerror = function(){ cb(new Error('Network error')); };
    xhr.send(JSON.stringify(payload));
  }
  function saveModelsNow(){
    apiPost({action:'save_models', models: models}, function(err){
      var status = document.getElementById('models_status');
      if (err) {
        if (status) status.textContent = 'Помилка збереження моделей: ' + err.message;
        return;
      }
      if (status) status.textContent = 'Моделі збережено ✔';
    });
  }

  // ── Обов'язкові поля промту ─────────────────────────────────────────────────
  var REQUIRED_PROMPT_FIELDS = {
    'pf_json_rule':            'JSON-правило',
    'pf_requirements_title':   'Заголовок блоку параметрів',
    'pf_input_title':          'Заголовок вхідного матеріалу',
    'pf_news_fields_on':       'Поля JSON при увімкненій новині',
    'pf_news_requirements_on': 'Вимоги до новини',
    'pf_tone_prefix':          'Префікс тональності',
    'pf_tone_short_rules':     'Короткі описи тональностей',
    'pf_depth_prefix':         'Префікс глибини рерайту',
    'pf_source_ref_rule':      'Інструкція для джерела'
  };

  function validatePromptFields() {
    var errors = [];
    for (var id in REQUIRED_PROMPT_FIELDS) {
      var el = document.getElementById(id);
      if (!el) continue;
      if ((el.value || '').trim() === '') errors.push('• ' + REQUIRED_PROMPT_FIELDS[id]);
    }
    // tone_short_rules: перевіряємо що є рівно 4 рядки
    var tsr = (document.getElementById('pf_tone_short_rules').value || '').trim();
    var tsrLines = tsr.split(/\n?---\n?/).map(function(l){ return l.trim(); }).filter(Boolean);
    if (tsrLines.length < 4) errors.push('• Короткі описи тональностей: потрібно 4 записи розділені --- (нейтральний, інтригуючий, емоційний, SEO), зараз ' + tsrLines.length);
    return errors;
  }

  function readPromptFields() {
    function val(id) { return (document.getElementById(id) && document.getElementById(id).value || '').trim(); }
    function lines(id) {
      var raw = val(id);
      return raw.split(/\n?---\n?/).map(function(s){ return s.trim(); }).filter(Boolean);
    }

    return {
      json_rule:             val('pf_json_rule'),
      requirements_title:    val('pf_requirements_title'),
      input_title:           val('pf_input_title'),
      news_fields_on:        val('pf_news_fields_on'),
      news_requirements_on:  val('pf_news_requirements_on'),
      tone_prefix:           val('pf_tone_prefix'),
      tone_short_rules:      (function(){
        var keys = ['neutral','intriguing','emotional','seo'];
        var vals = lines('pf_tone_short_rules');
        var m = {};
        keys.forEach(function(k, i){ m[k] = vals[i] || ''; });
        return m;
      }()),
      depth_prefix:          val('pf_depth_prefix'),
      depth_short_rules:     lines('pf_depth_short_rules'),
      source_ref_rule:       val('pf_source_ref_rule'),
      fb_checkbox_on:        val('pf_fb_checkbox_on'),
      fb_style_rules:        lines('pf_fb_style_rules'),
      facebook_when_disabled: 'omit'
    };
  }

  // Зберегти system prompt
  var saveSystemBtn = document.getElementById('save_system_default_btn');
  if (saveSystemBtn) {
    saveSystemBtn.addEventListener('click', function(){
      var text = (document.getElementById('system_default_override').value || '').trim();
      var status = document.getElementById('save_system_status');
      if (!text) { status.textContent = 'System prompt не може бути порожнім'; return; }
      if (!confirm('Зберегти system prompt?')) return;
      apiPost({action:'save_system_default_override', value: text}, function(err){
        if (err) { status.textContent = 'Помилка: ' + err.message; return; }
        status.textContent = 'System prompt збережено ✔';
      });
    });
  }

  // Зберегти параметри генерації (числа)
  var saveLimitsBtn = document.getElementById('save_prompt_limits_btn');
  if (saveLimitsBtn) {
    saveLimitsBtn.addEventListener('click', function(){
      var status = document.getElementById('save_limits_status');
      apiPost({action:'save_prompt_limits', limits:{
        headlines_count:  Number(document.getElementById('lim_headlines').value || 4),
        leads_count:      Number(document.getElementById('lim_leads').value || 2),
        article_max_chars:Number(document.getElementById('lim_article').value || 3000),
        facebook_max_chars:Number(document.getElementById('lim_fb').value || 400),
        lead_min_chars:   Number(document.getElementById('lim_lead_min').value || 150),
        lead_max_chars:   Number(document.getElementById('lim_lead_max').value || 180)
      }}, function(err){
        if (err) { status.textContent = 'Помилка: ' + err.message; return; }
        status.textContent = 'Параметри збережено ✔';
      });
    });
  }

  // Зберегти складові user-промту (поля)
  var saveFieldsBtn = document.getElementById('save_prompt_fields_btn');
  if (saveFieldsBtn) {
    saveFieldsBtn.addEventListener('click', function(){
      var status = document.getElementById('save_fields_status');
      var errors = validatePromptFields();
      if (errors.length) {
        status.innerHTML = '<span style="color:#A32D2D">Виправте помилки:<br>' + errors.join('<br>') + '</span>';
        return;
      }
      var fields = readPromptFields();
      // Зберігаємо через save_prompt_profiles з поточними числовими лімітами
      var currentProfiles = { user: Object.assign({
        headlines_count:   Number(document.getElementById('lim_headlines').value || 4),
        leads_count:       Number(document.getElementById('lim_leads').value || 2),
        article_max_chars: Number(document.getElementById('lim_article').value || 3000),
        facebook_max_chars:Number(document.getElementById('lim_fb').value || 400),
        lead_min_chars:    Number(document.getElementById('lim_lead_min').value || 150),
        lead_max_chars:    Number(document.getElementById('lim_lead_max').value || 180)
      }, fields) };
      apiPost({action:'save_prompt_profiles', profiles: currentProfiles}, function(err){
        if (err) { status.textContent = 'Помилка: ' + err.message; return; }
        status.textContent = 'Складові промту збережено ✔';
      });
    });
  }

  // Відновити за замовчуванням
  var restorePromptsBtn = document.getElementById('restore_prompts_defaults_btn');
  if (restorePromptsBtn) {
    restorePromptsBtn.addEventListener('click', function(){
      if (!confirm('Скинути system prompt та всі складові user-промту до значень за замовчуванням?')) return;
      apiPost({action:'restore_default_prompts'}, function(err, d){
        if (err) { alert('Не вдалося відновити: ' + err.message); return; }
        if (d && d.prompt_system) document.getElementById('system_default_override').value = d.prompt_system;
        if (d && d.prompt_profiles && d.prompt_profiles.user) {
          var p = d.prompt_profiles.user;
          function setVal(id, v) { var el = document.getElementById(id); if (el) el.value = v || ''; }
          function setLines(id, arr) { setVal(id, (arr || []).join('\n---\n')); }
          function setToneMap(id, m) {
            var lines = ['neutral','intriguing','emotional','seo'].map(function(k){ return k + ': ' + (m[k] || ''); });
            setVal(id, lines.join('\n'));
          }
          setVal('pf_json_rule',            p.json_rule);
          setVal('pf_requirements_title',   p.requirements_title);
          setVal('pf_input_title',          p.input_title);
          setVal('pf_news_fields_on',       p.news_fields_on);
          setVal('pf_news_requirements_on', p.news_requirements_on);
          setVal('pf_tone_prefix',          p.tone_prefix);
          setLines('pf_tone_short_rules', ['neutral','intriguing','emotional','seo'].map(function(k){ return (p.tone_short_rules || {})[k] || ''; }));
          setVal('pf_depth_prefix',         p.depth_prefix);
          setLines('pf_depth_short_rules',  p.depth_short_rules);
          setVal('pf_source_ref_rule',      p.source_ref_rule);
          setVal('pf_fb_checkbox_on',       p.fb_checkbox_on);
          setLines('pf_fb_style_rules',     p.fb_style_rules);
          if (p.headlines_count)   document.getElementById('lim_headlines').value  = p.headlines_count;
          if (p.leads_count)       document.getElementById('lim_leads').value       = p.leads_count;
          if (p.article_max_chars) document.getElementById('lim_article').value     = p.article_max_chars;
          if (p.facebook_max_chars)document.getElementById('lim_fb').value          = p.facebook_max_chars;
          if (p.lead_min_chars)    document.getElementById('lim_lead_min').value    = p.lead_min_chars;
          if (p.lead_max_chars)    document.getElementById('lim_lead_max').value    = p.lead_max_chars;
        }
        document.getElementById('save_system_status').textContent = 'Відновлено за замовчуванням ✔';
        document.getElementById('save_fields_status').textContent = 'Відновлено за замовчуванням ✔';
      });
    });
  }

  // Зберегти як за замовчуванням (записує поточний стан у prompts.json)
  var saveAsDefaultBtn = document.getElementById('save_as_default_btn');
  if (saveAsDefaultBtn) {
    saveAsDefaultBtn.addEventListener('click', function() {
      if (!confirm('Зберегти поточний system prompt та всі параметри user-промту як нові значення за замовчуванням?\n\nПісля цього кнопка «Відновити за замовчуванням» відновлюватиме саме ці значення.')) return;
      var status = document.getElementById('save_system_status');
      saveAsDefaultBtn.disabled = true;
      status.textContent = 'Збереження…';
      apiPost({action: 'save_as_default_prompts'}, function(err) {
        saveAsDefaultBtn.disabled = false;
        if (err) {
          status.innerHTML = '<span style="color:#A32D2D">Помилка: ' + err.message + '</span>';
          return;
        }
        status.textContent = '★ Збережено як за замовчуванням ✔';
      });
    });
  }

  // ── Резервні копії prompts.json ────────────────────────────────────────────
  function renderBackups(backups) {
    var el = document.getElementById('backups_list');
    if (!el) return;
    if (!backups || !backups.length) {
      el.textContent = 'Резервних копій ще немає. Вони з\'являться після першого збереження prompts.json.';
      return;
    }
    var html = '<table style="width:100%;border-collapse:collapse;font-size:11px">';
    html += '<tr><th style="text-align:left;padding:4px 8px 4px 0;border-bottom:1px solid #e8e2d4;color:#8a8278">Дата і час</th>';
    html += '<th style="text-align:left;padding:4px 0;border-bottom:1px solid #e8e2d4;color:#8a8278">Дія</th></tr>';
    backups.forEach(function(b) {
      html += '<tr>';
      html += '<td style="padding:6px 8px 6px 0;color:#1a1714">' + b.label + '</td>';
      html += '<td style="padding:6px 0"><button type="button" class="btn-mini muted" data-restore="' + b.name + '">Відновити</button></td>';
      html += '</tr>';
    });
    html += '</table>';
    el.innerHTML = html;
    el.querySelectorAll('[data-restore]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var name = btn.getAttribute('data-restore');
        var status = document.getElementById('backup_restore_status');
        if (!confirm('Відновити prompts.json зі збереженої копії ' + name + '?\n\nПоточний стан буде збережено як новий бекап.')) return;
        apiPost({action:'restore_prompt_backup', name: name}, function(err) {
          if (err) { status.innerHTML = '<span style="color:#A32D2D">Помилка: ' + err.message + '</span>'; return; }
          status.textContent = 'Відновлено ✔ · Перезавантажте сторінку щоб побачити зміни';
          loadBackups();
        });
      });
    });
  }
  function loadBackups() {
    apiPost({action:'get_prompt_backups'}, function(err, d) {
      if (err) { var el=document.getElementById('backups_list'); if(el) el.textContent='Помилка завантаження'; return; }
      renderBackups(d.backups || []);
    });
  }
  var backupsReloadBtn = document.getElementById('backups_reload_btn');
  if (backupsReloadBtn) backupsReloadBtn.addEventListener('click', loadBackups);
  // Завантажуємо бекапи при першому відкритті вкладки "Промти і параметри"
  document.querySelectorAll('.tab-btn[data-tab="prompts"]').forEach(function(tb) {
    tb.addEventListener('click', function() { if (document.getElementById('backups_list').textContent === 'Завантаження…') loadBackups(); }, {once: true});
  });

  var keysToggle = document.getElementById('keys_toggle');
  var keysSection = document.getElementById('keys_section');
  var keysToggleIcon = document.getElementById('keys_toggle_icon');
  if (keysToggle && keysSection) {
    keysToggle.addEventListener('click', function() {
      var open = keysSection.style.display !== 'none';
      keysSection.style.display = open ? 'none' : '';
      keysToggleIcon.textContent = open ? '+' : '−';
    });
  }

  var keyBtns = document.querySelectorAll('[data-save-key]');
  for (var iKey = 0; iKey < keyBtns.length; iKey++) {
    keyBtns[iKey].addEventListener('click', function(){
      var provider = this.getAttribute('data-save-key');
      var input = document.getElementById('k_' + provider);
      var value = (input && input.value || '').trim();
      if (!value) { alert('Введіть новий ключ у поле'); return; }
      if (value.indexOf('*') !== -1) { alert('Введіть реальний ключ, а не маску'); return; }

      apiPost({action:'save_key', provider: provider, value: value}, function(err){
        var status = document.getElementById('keys_status');
        if (err) {
          if (status) status.textContent = 'Помилка збереження ключа: ' + err.message;
          return;
        }
        input.value = '';
        input.placeholder = 'збережено ✔';
        if (status) status.textContent = 'Ключ ' + provider + ' збережено ✔';
      });
    });
  }

  var pwdBtns = document.querySelectorAll('[data-save-password]');
  for (var iPwd = 0; iPwd < pwdBtns.length; iPwd++) {
    pwdBtns[iPwd].addEventListener('click', function(){
      var target = this.getAttribute('data-save-password');
      var input = document.getElementById('pwd_' + target);
      var value = (input && input.value || '').trim();
      if (value.length < 8) { alert('Пароль має бути не коротше 8 символів'); return; }
      apiPost({action:'save_password', target: target, value: value}, function(err){
        var status = document.getElementById('password_status');
        if (err) {
          if (status) status.textContent = 'Помилка збереження пароля: ' + err.message;
          return;
        }
        input.value = '';
        if (status) status.textContent = 'Пароль для ' + target + ' збережено ✔';
      });
    });
  }

  function startEdit(i){
    var m = models[i]; if(!m) return;
    editIndex = i;
    document.getElementById('m_id').value = m.id || '';
    document.getElementById('m_label').value = m.label || '';
    document.getElementById('m_provider').value = m.provider || 'anthropic';
    document.getElementById('m_inp').value = Number(m.inp || 0).toFixed(2);
    document.getElementById('m_out').value = Number(m.out || 0).toFixed(2);
    document.getElementById('m_max_tokens').value = m.max_tokens || 8000;
    // web_search auto-set by provider on save
    document.getElementById('m_add').textContent = 'Зберегти зміни';
    document.getElementById('m_cancel').style.display = 'inline-block';
  }

  document.getElementById('m_add').addEventListener('click', function(){
    var m = readForm();
    if (!m.id || !m.label) { alert('Заповніть ID і назву моделі'); return; }
    if (!ALLOWED_PROVIDERS.includes(m.provider)) { alert('Невідомий провайдер: ' + m.provider); return; }
    if (editIndex >= 0) models[editIndex] = m; else models.push(m);
    models = dedupeModels(models);
    clearForm();
    render();
    saveModelsNow();
  });
  document.getElementById('m_cancel').addEventListener('click', clearForm);

  // Прибрано обробку кліків на стрілки вверх/вниз
  tbody.addEventListener('click', function(e){
    var edit = e.target.getAttribute('data-edit');
    var del  = e.target.getAttribute('data-del');
    var tog  = e.target.getAttribute('data-toggle');
    if (edit !== null) startEdit(Number(edit));
    if (del !== null) {
      if (confirm('Видалити модель?')) { models.splice(Number(del),1); clearForm(); render(); saveModelsNow(); }
    }
    if (tog !== null) {
      var idx = parseInt(tog, 10);
      if (!isNaN(idx) && models[idx]) {
        models[idx].enabled = e.target.checked;
        render();
        saveModelsNow();
      }
    }
  });

  // ── Drag-and-drop сортування моделей ───────────────────────────────────────
  var dragSrc = null;

  function onDragStart(e) {
    dragSrc = this;
    this.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.getAttribute('data-row'));
  }
  function onDragEnd() {
    this.style.opacity = '';
    tbody.querySelectorAll('tr').forEach(function(r){ r.classList.remove('drag-over'); });
  }
  function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    tbody.querySelectorAll('tr').forEach(function(r){ r.classList.remove('drag-over'); });
    this.classList.add('drag-over');
    return false;
  }
  function onDrop(e) {
    e.stopPropagation();
    var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
    var to   = parseInt(this.getAttribute('data-row'), 10);
    if (!isNaN(from) && !isNaN(to) && from !== to) {
      var moved = models.splice(from, 1)[0];
      models.splice(to, 0, moved);
      render();
      saveModelsNow();
    }
    return false;
  }

  function bindDrag() {
    tbody.querySelectorAll('tr[draggable]').forEach(function(row) {
      row.addEventListener('dragstart',  onDragStart);
      row.addEventListener('dragend',    onDragEnd);
      row.addEventListener('dragover',   onDragOver);
      row.addEventListener('drop',       onDrop);
    });
  }

  var _origRender = render;
  render = function() { _origRender(); bindDrag(); };

  var tabBtns = document.querySelectorAll('.tab-btn');
  var panes = document.querySelectorAll('.tab-pane');
  for (var tb=0; tb<tabBtns.length; tb++) {
    tabBtns[tb].addEventListener('click', function(){
      var tab = this.getAttribute('data-tab');
      for (var i=0;i<tabBtns.length;i++) tabBtns[i].classList.toggle('active', tabBtns[i]===this);
      for (var j=0;j<panes.length;j++) panes[j].classList.toggle('active', panes[j].getAttribute('data-pane')===tab);
      if (tab === 'logs') loadLogs(document.getElementById('log-date') ? document.getElementById('log-date').value : '');
    });
  }

  // ── Logs tab ────────────────────────────────────────────────────────────────
  function escL(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function numFmtL(n){ return String(parseInt(n)||0).replace(/\B(?=(\d{3})+(?!\d))/g,' '); }
  function fmtCostL(v){ v=parseFloat(v)||0; if(v<0.0001) return '< $0.0001'; return '$'+v.toFixed(4); }
  function logCardHtml(lbl,val,cls){ return '<div class="log-card"><div class="log-card-lbl">'+lbl+'</div><div class="log-card-val '+cls+'">'+val+'</div></div>'; }

  function loadLogs(dateFilter) {
    var wrap  = document.getElementById('log-table-wrap');
    var cards = document.getElementById('log-cards');
    if (!wrap) return;
    wrap.innerHTML  = '<div style="padding:24px;text-align:center;font-family:Roboto Mono,monospace;font-size:11px;color:#8a8278">Завантаження…</div>';
    cards.innerHTML = '';
    apiPost({action:'get_logs', date: dateFilter||''}, function(err, d) {
      if (err) { wrap.innerHTML='<div style="padding:20px;color:#8e2d16;font-size:12px">Помилка: '+escL(err.message)+'</div>'; return; }
      var s = d.summary || {};
      cards.innerHTML =
        logCardHtml('Запитів', s.cnt||0, '') +
        logCardHtml('Вартість', fmtCostL(s.total_cost), 'red') +
        logCardHtml('Вхід токенів', numFmtL(s.total_inp), '') +
        logCardHtml('Вихід токенів', numFmtL(s.total_out), '') +
        logCardHtml('Cache hits', numFmtL(s.total_cache_r), 'green');
      var rows = d.rows || [];
      if (!rows.length) {
        wrap.innerHTML = '<div style="padding:28px;text-align:center;font-family:Roboto Mono,monospace;font-size:11px;color:#8a8278">Записів не знайдено</div>';
        return;
      }
      var thStyle = 'style="padding:9px 11px;font-family:Roboto Mono,monospace;font-size:9px;letter-spacing:.12em;text-align:left;white-space:nowrap;background:#1a1714;color:#f5f2eb"';
      var html = '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr>'
        + '<th '+thStyle+'>Дата/час</th><th '+thStyle+'>Модель</th><th '+thStyle+'>Вхід</th>'
        + '<th '+thStyle+'>Вихід</th><th '+thStyle+'>Cache</th><th '+thStyle+'>Вартість</th>'
        + '<th '+thStyle+'>Тривалість</th><th '+thStyle+'>Пошук</th><th '+thStyle+'>Кеш</th>'
        + '</tr></thead><tbody id="log-tbody"></tbody></table>';
      wrap.innerHTML = html;
      var tbody = document.getElementById('log-tbody');
      var tbHtml = '';
      rows.forEach(function(r, idx) {
        var bg   = idx%2===0 ? '' : 'background:#faf8f3';
        var dt   = escL((r.date||'')+' '+(r.time||''));
        var mod  = escL(r.model||'');
        var inp  = numFmtL(r.inp||r.input_tokens||0);
        var out  = numFmtL(r.out||r.output_tokens||0);
        var cw   = parseInt(r.cache_write)||0;
        var cr   = parseInt(r.cache_read)||0;
        var cost = fmtCostL(r.cost);
        var dur  = escL(r.duration||'—');
        var isWeb = r.web==='web'||r.web===1||r.web==='1';
        var webTag = isWeb ? '<span class="log-tag tag-web">веб</span>' : '<span class="log-tag tag-noweb">без</span>';
        var cs = r.cache_status||'';
        var cTag = cs==='cache-hit' ? '<span class="log-tag tag-hit">hit</span>'
                 : cs==='cache-write' ? '<span class="log-tag tag-write">write</span>'
                 : '<span class="log-tag tag-nocache">—</span>';
        var costColor = parseFloat(r.cost)>0.05 ? 'color:#b5401a' : parseFloat(r.cost)>0.01 ? 'color:#8a6a20' : '';
        var cacheCell = (cw>0 ? '<span style="color:#8a6a20">w:'+numFmtL(cw)+'</span> ' : '') + (cr>0 ? '<span style="color:#2a5a30">r:'+numFmtL(cr)+'</span>' : (cw===0?'—':''));
        var td = 'style="padding:8px 11px;border-bottom:1px solid #f0ece4"';
        tbHtml += '<tr class="log-row" data-idx="'+idx+'" style="'+bg+'">'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;white-space:nowrap;font-family:Roboto Mono,monospace">'+dt+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace;font-size:11px">'+mod+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace">'+inp+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace">'+out+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace;font-size:10px">'+cacheCell+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace;font-weight:600;'+costColor+'">'+cost+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4;font-family:Roboto Mono,monospace;color:#8a8278">'+dur+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4">'+webTag+'</td>'
          + '<td '+td+' style="padding:8px 11px;border-bottom:1px solid #f0ece4">'+cTag+'</td>'
          + '</tr>'
          + '<tr class="row-detail" id="ld-'+idx+'">'
          + '<td colspan="9"><div class="detail-grid">'
          + '<div class="detail-key">Модель (id)</div><div class="detail-val">'+escL(r.model||'—')+'</div>'
          + '<div class="detail-key">Провайдер</div><div class="detail-val">'+escL(r.provider||'—')+'</div>'
          + '<div class="detail-key">Вхід / Вихід</div><div class="detail-val">'+numFmtL(parseInt(r.inp||0))+' / '+numFmtL(parseInt(r.out||0))+' tok</div>'
          + (cw>0 ? '<div class="detail-key">Cache write</div><div class="detail-val">'+numFmtL(cw)+' tok</div>' : '')
          + (cr>0 ? '<div class="detail-key">Cache read</div><div class="detail-val">'+numFmtL(cr)+' tok</div>' : '')
          + '<div class="detail-key">Вартість</div><div class="detail-val">'+cost+'</div>'
          + '<div class="detail-key">Тривалість</div><div class="detail-val">'+escL(r.duration||'—')+' с</div>'
          + '<div class="detail-key">Довжина промту</div><div class="detail-val">'+numFmtL(parseInt(r.prompt_len||0))+' симв.</div>'
          + (r.error ? '<div class="detail-key" style="color:#8e2d16">Помилка</div><div class="detail-val" style="color:#8e2d16">'+escL(r.error)+'</div>' : '')
          + '</div></td></tr>';
      });
      tbody.innerHTML = tbHtml;
      tbody.querySelectorAll('.log-row').forEach(function(row) {
        row.addEventListener('click', function() {
          var idx = this.getAttribute('data-idx');
          var det = document.getElementById('ld-'+idx);
          if (!det) return;
          var wasOpen = det.classList.contains('open');
          tbody.querySelectorAll('.row-detail.open').forEach(function(d){ d.classList.remove('open'); });
          tbody.querySelectorAll('.log-row.expanded').forEach(function(r){ r.classList.remove('expanded'); });
          if (!wasOpen) { det.classList.add('open'); row.classList.add('expanded'); }
        });
      });
    });
  }

  (function() {
    var logDate   = document.getElementById('log-date');
    var filterBtn = document.getElementById('log-filter-btn');
    var clearBtn  = document.getElementById('log-clear-btn');
    var reloadBtn = document.getElementById('log-reload-btn');
    if (filterBtn) filterBtn.addEventListener('click', function(){ loadLogs(logDate ? logDate.value : ''); });
    if (clearBtn)  clearBtn.addEventListener('click',  function(){ if(logDate) logDate.value=''; loadLogs(''); });
    if (reloadBtn) reloadBtn.addEventListener('click', function(){ loadLogs(logDate ? logDate.value : ''); });

    var btnLoadApi = document.getElementById('btn_load_api');
    if (btnLoadApi) {
      btnLoadApi.addEventListener('click', function() {
        var btn = this;
        btn.disabled = true; btn.textContent = 'Завантаження…';
        apiPost({action:'get_api_responses'}, function(err, d) {
          btn.disabled = false; btn.textContent = 'Оновити';
          var container = document.getElementById('api_responses_list');
          if (err || !d.responses || !d.responses.length) {
            container.innerHTML = '<span style="color:#8a8278">Відповідей ще немає</span>';
            return;
          }
          var html = '';
          d.responses.forEach(function(r, i) {
            var isErr = r.type==='error';
            var body = '';
            try { body = JSON.stringify(JSON.parse(r.body), null, 2); } catch(e) { body = r.body||''; }
            html += '<div class="api-entry">'
              + '<div class="api-entry-hdr" data-api="'+i+'">'
              + '<span class="log-tag '+(isErr?'tag-err':'tag-ok')+'">'+(isErr?'Помилка '+r.code:'OK '+r.code)+'</span>'
              + '<span style="font-family:Roboto Mono,monospace;font-size:11px">'+escL(r.ts||'')+'</span>'
              + '<span style="font-family:Roboto Mono,monospace;font-size:11px;color:#8a8278">'+escL((r.provider||'')+' / '+(r.model||''))+'</span>'
              + '</div>'
              + '<div class="api-entry-body" id="api-body-'+i+'"><pre>'+escL(body)+'</pre></div>'
              + '</div>';
          });
          container.innerHTML = html;
          container.querySelectorAll('.api-entry-hdr').forEach(function(hdr) {
            hdr.addEventListener('click', function() {
              var b = document.getElementById('api-body-'+this.getAttribute('data-api'));
              if (b) b.classList.toggle('open');
            });
          });
        });
      });
    }
  })();

  if (!Array.isArray(models)) models = [];
  models = dedupeModels(models);
  render();
  // ── Import / Export ─────────────────────────────────────────────────────

  var importedPayload = null;

  // EXPORT
  var btnExport = document.getElementById('btn_export');
  if (btnExport) {
    btnExport.addEventListener('click', function() {
      var status = document.getElementById('export_status');
      btnExport.disabled = true;
      status.textContent = 'Завантаження...';
      apiPost({ action: 'export_settings' }, function(err, d) {
        btnExport.disabled = false;
        if (err) { status.textContent = 'Помилка: ' + err.message; return; }
        var json = JSON.stringify(d.data, null, 2);
        var blob = new Blob([json], { type: 'application/json' });
        var url  = URL.createObjectURL(blob);
        var now  = new Date().toISOString().slice(0, 10);
        var a = document.createElement('a');
        a.href = url; a.download = 'ainewswriter-backup-' + now + '.json';
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
        status.textContent = 'Файл збережено ✔';
      });
    });
  }

  // IMPORT — file pick
  var importFile = document.getElementById('import_file');
  if (importFile) {
    importFile.addEventListener('change', function() {
      var file = this.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function(e) {
        var status  = document.getElementById('import_status');
        var preview = document.getElementById('import_preview');
        var summary = document.getElementById('import_summary');
        try {
          importedPayload = JSON.parse(e.target.result);
        } catch(ex) {
          status.textContent = 'Невалідний JSON: ' + ex.message;
          preview.style.display = 'none';
          return;
        }
        if (!importedPayload.__version) {
          status.textContent = 'Файл не схожий на backup ainewswriter (відсутній __version)';
          preview.style.display = 'none';
          return;
        }
        // show summary
        var lines = [];
        if (importedPayload.__exported_at) lines.push('📅 Дата: ' + importedPayload.__exported_at);
        if (Array.isArray(importedPayload.models))
          lines.push('🤖 Моделі: ' + importedPayload.models.length + ' шт.');
        if (importedPayload.prompt_profiles)  lines.push('⚙️ Профілі промтів: є');
        if (importedPayload.system_prompt_default_override !== undefined)
          lines.push('📝 System prompt: є (' + String(importedPayload.system_prompt_default_override).length + ' симв.)');
        if (importedPayload.prompts_json)     lines.push('📄 prompts.json: є');
        if (importedPayload.api_keys) {
          var keyCount = Object.values(importedPayload.api_keys).filter(function(v){ return v && v.length > 0; }).length;
          lines.push('🔑 API-ключі: ' + keyCount + ' шт.');
        }
        summary.innerHTML = lines.join('<br>');

        var cnt = document.getElementById('imp_models_count');
        if (cnt) cnt.textContent = Array.isArray(importedPayload.models) ? importedPayload.models.length : 0;

        // show/hide checkboxes depending on what file contains
        document.getElementById('imp_models').closest('label').style.display    = Array.isArray(importedPayload.models) ? '' : 'none';
        document.getElementById('imp_profiles').closest('label').style.display  = importedPayload.prompt_profiles ? '' : 'none';
        document.getElementById('imp_system').closest('label').style.display    = (importedPayload.system_prompt_default_override !== undefined) ? '' : 'none';
        document.getElementById('imp_prompts_json').closest('label').style.display = importedPayload.prompts_json ? '' : 'none';
        document.getElementById('imp_api_keys').closest('label').style.display = importedPayload.api_keys ? '' : 'none';

        preview.style.display = '';
        status.textContent = '';
      };
      reader.readAsText(file);
    });
  }

  // IMPORT — confirm
  var btnImport = document.getElementById('btn_import_confirm');
  if (btnImport) {
    btnImport.addEventListener('click', function() {
      if (!importedPayload) return;
      if (!confirm('Застосувати вибрані налаштування з файлу? Поточні дані будуть перезаписані.')) return;

      // Build selective payload
      var sel = {};
      sel.__version = importedPayload.__version;
      if (document.getElementById('imp_models').checked     && Array.isArray(importedPayload.models))
        sel.models = importedPayload.models;
      if (document.getElementById('imp_profiles').checked   && importedPayload.prompt_profiles)
        sel.prompt_profiles = importedPayload.prompt_profiles;
      if (document.getElementById('imp_system').checked     && importedPayload.system_prompt_default_override !== undefined)
        sel.system_prompt_default_override = importedPayload.system_prompt_default_override;
      if (document.getElementById('imp_prompts_json').checked && importedPayload.prompts_json)
        sel.prompts_json = importedPayload.prompts_json;
      if (document.getElementById('imp_api_keys').checked && importedPayload.api_keys)
        sel.api_keys = importedPayload.api_keys;

      var status = document.getElementById('import_status');
      btnImport.disabled = true;
      status.textContent = 'Імпортую...';

      apiPost({ action: 'import_settings', data: sel }, function(err, d) {
        btnImport.disabled = false;
        if (err) { status.textContent = 'Помилка: ' + err.message; return; }
        var res = d.imported || {};
        var msg = 'Імпорт виконано ✔';
        if (res.models_count !== undefined)  msg += ' · моделей: ' + res.models_count;
        if (res.has_prompts_json) msg += ' · prompts.json оновлено';
        if (res.keys_imported)    msg += ' · ключів: ' + res.keys_imported;
        status.textContent = msg;
        // Reload page to show updated data
        setTimeout(function(){ window.location.reload(); }, 1200);
      });
    });
  }

})();
</script>

<footer class="site-footer" style="margin-top:32px">
  AI Newswriter v<?= APP_VERSION ?> &nbsp;&middot;&nbsp; Адмін-панель &nbsp;&middot;&nbsp; <a href="/">Редактор</a>
</footer>

</body>
</html>
<?php
// Закриваємо сесію
session_write_close();
?>
