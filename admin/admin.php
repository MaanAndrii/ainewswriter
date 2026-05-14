<?php
require_once __DIR__ . '/../core/app_settings.php';

define('LOG_FILE', __DIR__ . '/../storage/requests.log');

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

function stats_from_log($file) {
  $out = [
    'total_requests' => 0,
    'total_cost' => 0.0,
    'total_inp' => 0,
    'total_out' => 0,
    'by_model' => [],
    'by_day' => [],
  ];

  if (!file_exists($file)) return $out;
  $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $r = parse_log_entry($line);
    if (!$r) continue;

    $date = $r['date'];
    $model = $r['model'];
    $inp = (int)$r['inp'];
    $outTok = (int)$r['out'];
    $cost = (float)$r['cost'];

    $out['total_requests']++;
    $out['total_cost'] += $cost;
    $out['total_inp'] += $inp;
    $out['total_out'] += $outTok;

    if (!isset($out['by_model'][$model])) {
      $out['by_model'][$model] = ['req' => 0, 'cost' => 0.0, 'inp' => 0, 'out' => 0];
    }
    $out['by_model'][$model]['req']++;
    $out['by_model'][$model]['cost'] += $cost;
    $out['by_model'][$model]['inp'] += $inp;
    $out['by_model'][$model]['out'] += $outTok;

    if (!isset($out['by_day'][$date])) {
      $out['by_day'][$date] = ['req' => 0, 'cost' => 0.0];
    }
    $out['by_day'][$date]['req']++;
    $out['by_day'][$date]['cost'] += $cost;
  }

  krsort($out['by_day']);
  return $out;
}

$stats = stats_from_log(LOG_FILE);
$modelsJsonPretty = json_encode($settings['models'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$promptsFile = dirname(__DIR__) . '/prompts.json';
$promptsJsonPretty = file_exists($promptsFile) ? file_get_contents($promptsFile) : '{}';
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Адмін-панель</title>
<style>
*{box-sizing:border-box} body{margin:0;background:#f5f2eb;color:#1a1714;font-family:'Roboto',sans-serif}
.hdr{background:#1a1714;color:#f5f2eb;padding:14px 28px;border-bottom:3px solid #b5401a;display:flex;justify-content:space-between;align-items:center}
.hdr h1{margin:0;font-family:'Roboto',sans-serif;font-size:22px}.hdr a{color:#8a8278;text-decoration:none;font-family:'Roboto Mono',monospace;font-size:11px}
.hdr a:hover{color:#f5f2eb}.wrap{max-width:1200px;margin:24px auto;padding:0 20px;display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
.card{background:#fff;border:1px solid #e8e2d4;border-radius:6px;padding:18px}.ttl{font-family:'Roboto Mono',monospace;font-size:11px;letter-spacing:.12em;color:#8a8278;text-transform:uppercase;margin-bottom:10px}
.lbl{display:block;margin:12px 0 6px;font-family:'Roboto Mono',monospace;font-size:10px;letter-spacing:.12em;color:#8a8278;text-transform:uppercase}
input[type=password],input[type=text],input[type=number],select,textarea{width:100%;border:1px solid #d8d0be;border-radius:4px;padding:10px 12px;font-size:13px;background:#fff} textarea{min-height:160px;resize:vertical;font-family:'Roboto Mono',monospace;line-height:1.5}
textarea.big{min-height:250px}.btn{margin-top:14px;background:#b5401a;color:#fff;border:0;border-radius:4px;padding:10px 16px;font-family:'Roboto Mono',monospace;font-size:11px;letter-spacing:.09em;text-transform:uppercase;cursor:pointer}
.small{font-size:12px;color:#8a8278}.ok{color:#2a5a30;font-size:13px;margin-top:8px}.err{color:#b5401a;font-size:13px;margin-top:8px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pill{display:inline-block;background:#faf8f3;border:1px solid #e8e2d4;padding:6px 9px;border-radius:4px;font-family:'Roboto Mono',monospace;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}th{font-family:'Roboto Mono',monospace;font-size:10px;color:#8a8278;text-transform:uppercase}
.model-grid{display:grid;grid-template-columns:2fr 1.3fr .8fr .8fr .8fr;gap:8px;align-items:end}
.btn-mini{background:#1a1714;color:#fff;border:0;border-radius:4px;padding:7px 10px;font-family:'Roboto Mono',monospace;font-size:10px;cursor:pointer}
.btn-mini.danger{background:#8e2d16}.btn-mini.muted{background:#8a8278}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tab-btn{background:#fff;border:1px solid #d8d0be;border-radius:4px;padding:8px 12px;font-family:'Roboto Mono',monospace;font-size:11px;cursor:pointer}.tab-btn.active{background:#1a1714;color:#fff;border-color:#1a1714}.tab-pane{display:none}.tab-pane.active{display:block}
@media(max-width:980px){.wrap{grid-template-columns:1fr}}
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
  </div>

  <section class="tab-pane active" data-pane="ai">
    <div class="card">
      <div class="ttl">Налаштування AI</div>
      <form onsubmit="return false;">
        <label class="lbl">API ключі</label>
        <table id="keys_table">
          <tr><th>Provider</th><th>API ключ</th><th>Дія</th></tr>
          <tr><td>anthropic</td><td><input type="password" id="k_anthropic" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['anthropic'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['anthropic'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="anthropic">Зберегти</button></td></tr>
          <tr><td>xai</td><td><input type="password" id="k_xai" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['xai'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['xai'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="xai">Зберегти</button></td></tr>
          <tr><td>gemini</td><td><input type="password" id="k_gemini" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['gemini'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['gemini'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="gemini">Зберегти</button></td></tr>
          <tr><td>mistral</td><td><input type="password" id="k_mistral" data-mask="<?= htmlspecialchars(mask_val($runtimeKeys['mistral'] ?? '')) ?>" placeholder="<?= htmlspecialchars(mask_val($runtimeKeys['mistral'] ?? '')) ?>"></td><td><button type="button" class="btn-mini" data-save-key="mistral">Зберегти</button></td></tr>
        </table>
        <div class="small" id="keys_status" style="margin-top:6px"></div>
        <div class="small" style="margin-bottom:10px">Ключі записуються у файл env: <code><?= htmlspecialchars(get_env_file_path()) ?></code>. Збереження ключа відбувається одразу по кнопці в таблиці.</div>

        <label class="lbl">Моделі AI</label>
        <div class="model-grid">
          <div><label class="small">ID</label><input type="text" id="m_id" placeholder="claude-sonnet-4-6"></div>
          <div><label class="small">Назва</label><input type="text" id="m_label" placeholder="Sonnet 4.6"></div>
          <div><label class="small">Provider</label><select id="m_provider"><option value="anthropic">anthropic</option><option value="xai">xai</option><option value="gemini">gemini</option><option value="mistral">mistral</option></select></div>
          <div><label class="small">Inp $/1M</label><input type="number" id="m_inp" step="0.01" value="3.00"></div>
          <div><label class="small">Out $/1M</label><input type="number" id="m_out" step="0.01" value="15.00"></div>
        </div>
        <div class="row" style="margin-top:8px">
          <div><label class="small"><input type="checkbox" id="m_web_search" checked> Дозволити web_search</label></div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button type="button" class="btn-mini muted" id="m_cancel" style="display:none">Скасувати редагування</button>
            <button type="button" class="btn-mini" id="m_add">Додати модель</button>
          </div>
        </div>
        <table id="models_table" style="margin-top:10px">
          <tr><th>Порядок</th><th>ID</th><th>Назва</th><th>Provider</th><th>Inp</th><th>Out</th><th>Web</th><th>Дії</th></tr>
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
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
        <button type="button" class="btn-mini muted" id="restore_prompts_defaults_btn">Відновити за замовчуванням</button>
        <button type="button" class="btn-mini danger" id="save_system_default_btn">Зберегти system prompt</button>
      </div>
      <div class="small" id="save_system_status" style="text-align:right;margin-top:4px"></div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="ttl">Параметри генерації</div>
      <div class="row">
        <div><label class="small">К-сть заголовків</label><input type="number" id="lim_headlines" min="1" max="10" value="<?= (int)($pp['headlines_count'] ?? 4) ?>"></div>
        <div><label class="small">К-сть лідів</label><input type="number" id="lim_leads" min="1" max="5" value="<?= (int)($pp['leads_count'] ?? 2) ?>"></div>
        <div><label class="small">Макс. символів новини</label><input type="number" id="lim_article" min="300" max="10000" value="<?= (int)($pp['article_max_chars'] ?? 3000) ?>"></div>
        <div><label class="small">Макс. символів Facebook</label><input type="number" id="lim_fb" min="50" max="2000" value="<?= (int)($pp['facebook_max_chars'] ?? 400) ?>"></div>
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

      <label class="lbl" style="margin-top:10px">Вимоги до новини <span style="color:#A32D2D">*</span> <span class="small" style="font-weight:400">— підтримує {{headlines_count}}, {{leads_count}}, {{article_max_chars}}, {{tone_label}}</span></label>
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

      <label class="lbl" style="margin-top:10px">Web-пошук увімкнено <span style="color:#A32D2D">*</span></label>
      <input type="text" id="pf_websearch_on" value="<?= pp_str($pp,'websearch_on') ?>">

      <label class="lbl" style="margin-top:10px">Web-пошук вимкнено <span style="color:#A32D2D">*</span></label>
      <input type="text" id="pf_websearch_off" value="<?= pp_str($pp,'websearch_off') ?>">

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
    <div class="card">
      <div class="ttl">Останні логи запитів</div>
      <table>
        <tr><th>Час</th><th>Модель</th><th>Провайдер</th><th>Inp</th><th>Out</th><th>Cost</th><th>Статус</th></tr>
        <?php
        $logRows = [];
        if (file_exists(LOG_FILE)) {
          $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          if (is_array($lines)) {
            $lines = array_reverse($lines);
            foreach ($lines as $line) {
              $r = parse_log_entry($line);
              if (!$r) continue;
              $logRows[] = $r;
              if (count($logRows) >= 100) break;
            }
          }
        }
        ?>
        <?php if (!$logRows): ?>
        <tr><td colspan="7">Логи поки відсутні.</td></tr>
        <?php else: foreach ($logRows as $r): ?>
        <tr>
          <td><?= htmlspecialchars(($r['date'] ?? '') . ' ' . ($r['time'] ?? '')) ?></td>
          <td style="font-family:'Roboto Mono',monospace"><?= htmlspecialchars($r['model'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['provider'] ?? '') ?></td>
          <td><?= (int)($r['inp'] ?? 0) ?></td>
          <td><?= (int)($r['out'] ?? 0) ?></td>
          <td>$<?= number_format((float)($r['cost'] ?? 0), 4, '.', '') ?></td>
          <td><?= htmlspecialchars($r['cache_status'] ?? '-') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </table>
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
          <label style="font-size:13px"><input type="checkbox" id="imp_api_keys" checked> API-ключі (anthropic, xai, gemini, mistral)</label>
        </div>

        <button type="button" class="btn-mini danger" id="btn_import_confirm">Застосувати імпорт</button>
      </div>

      <div class="small" id="import_status" style="margin-top:8px"></div>
    </div>
  </section>
</div>
<script>
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
      inp: Number(document.getElementById('m_inp').value || 0),
      out: Number(document.getElementById('m_out').value || 0),
      web_search: !!document.getElementById('m_web_search').checked
    };
  }
  function clearForm(){
    document.getElementById('m_id').value = '';
    document.getElementById('m_label').value = '';
    document.getElementById('m_provider').value = 'anthropic';
    document.getElementById('m_inp').value = '3.00';
    document.getElementById('m_out').value = '15.00';
    document.getElementById('m_web_search').checked = true;
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
      html += '<tr draggable="true" data-row="'+i+'">'
        + '<td style="font-family:Roboto Mono,monospace;cursor:grab" class="drag-handle">☰</td>'
        + '<td style="font-family:Roboto Mono,monospace">'+esc(m.id)+'</td>'
        + '<td>'+esc(m.label)+'</td>'
        + '<td>'+esc(m.provider)+'</td>'
        + '<td>'+Number(m.inp).toFixed(2)+'</td>'
        + '<td>'+Number(m.out).toFixed(2)+'</td>'
        + '<td>'+(m.web_search ? 'так' : 'ні')+'</td>'
        + '<td><button type="button" class="btn-mini" data-edit="'+i+'">Ред.</button> <button type="button" class="btn-mini danger" data-del="'+i+'">Вид.</button></td>'
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
    'pf_depth_instr':          'Інструкції глибини',
    'pf_websearch_on':         'Web-пошук увімкнено',
    'pf_websearch_off':        'Web-пошук вимкнено'
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
    // depth_instr: 4 рядки
    var di = (document.getElementById('pf_depth_instr').value || '').trim();
    var diLines = di.split(/\n?---\n?/).map(function(l){ return l.trim(); }).filter(Boolean);
    if (diLines.length < 4) errors.push('• Інструкції глибини: потрібно 4 записи розділені --- (зараз ' + diLines.length + ')');
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
      depth_instr:           lines('pf_depth_instr'),
      depth_short_rules:     lines('pf_depth_short_rules'),
      source_ref_rule:       val('pf_source_ref_rule'),
      websearch_on:          val('pf_websearch_on'),
      websearch_off:         val('pf_websearch_off'),
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
        facebook_max_chars:Number(document.getElementById('lim_fb').value || 400)
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
        facebook_max_chars:Number(document.getElementById('lim_fb').value || 400)
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
          setLines('pf_depth_instr',        p.depth_instr);
          setLines('pf_depth_short_rules',  p.depth_short_rules);
          setVal('pf_source_ref_rule',      p.source_ref_rule);
          setVal('pf_websearch_on',         p.websearch_on);
          setVal('pf_websearch_off',        p.websearch_off);
          setVal('pf_fb_checkbox_on',       p.fb_checkbox_on);
          setLines('pf_fb_style_rules',     p.fb_style_rules);
          if (p.headlines_count)   document.getElementById('lim_headlines').value  = p.headlines_count;
          if (p.leads_count)       document.getElementById('lim_leads').value       = p.leads_count;
          if (p.article_max_chars) document.getElementById('lim_article').value     = p.article_max_chars;
          if (p.facebook_max_chars)document.getElementById('lim_fb').value          = p.facebook_max_chars;
        }
        document.getElementById('save_system_status').textContent = 'Відновлено за замовчуванням ✔';
        document.getElementById('save_fields_status').textContent = 'Відновлено за замовчуванням ✔';
      });
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
    document.getElementById('m_web_search').checked = !!m.web_search;
    document.getElementById('m_add').textContent = 'Зберегти зміни';
    document.getElementById('m_cancel').style.display = 'inline-block';
  }

  document.getElementById('m_add').addEventListener('click', function(){
    var m = readForm();
    if (!m.id || !m.label) { alert('Заповніть ID і назву моделі'); return; }
    if (m.provider !== 'anthropic' && m.provider !== 'xai' && m.provider !== 'gemini' && m.provider !== 'mistral') { alert('Provider має бути anthropic, xai, gemini або mistral'); return; }
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
    var del = e.target.getAttribute('data-del');
    if (edit !== null) startEdit(Number(edit));
    if (del !== null) {
      if (confirm('Видалити модель?')) { models.splice(Number(del),1); clearForm(); render(); saveModelsNow(); }
    }
  });

  // Прибрано обробку drag-and-drop для переміщення рядків
  var tabBtns = document.querySelectorAll('.tab-btn');
  var panes = document.querySelectorAll('.tab-pane');
  for (var tb=0; tb<tabBtns.length; tb++) {
    tabBtns[tb].addEventListener('click', function(){
      var tab = this.getAttribute('data-tab');
      for (var i=0;i<tabBtns.length;i++) tabBtns[i].classList.toggle('active', tabBtns[i]===this);
      for (var j=0;j<panes.length;j++) panes[j].classList.toggle('active', panes[j].getAttribute('data-pane')===tab);
    });
  }

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
</body>
</html>
<?php
// Закриваємо сесію
session_write_close();
?>
