<?php
/**
 * log_viewer.php — перегляд логів запитів
 * Захищений паролем. Відкрий у браузері: https://your-site.com/log_viewer.php
 */

// ── НАЛАШТУВАННЯ ──────────────────────────────────────────────
define('LOG_FILE',     __DIR__ . '/../storage/requests.log');
// ─────────────────────────────────────────────────────────────

session_start();
require_once __DIR__ . '/../core/app_settings.php';

// Авторизація
if (isset($_POST['pwd'])) {
  if ($_POST['pwd'] === get_auth_password('LOG_PASSWORD', 'change-me-now')) $_SESSION['log_auth'] = true;
  else $auth_error = true;
}
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: /admin/log_viewer.php');
  exit;
}

if (empty($_SESSION['log_auth'])) {
?><!DOCTYPE html>
<html lang="uk">
<head><meta charset="UTF-8"><title>Логи — вхід</title>
<style>
  body { background:#f5f2eb; font-family:'Roboto',sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .box { background:#fff; border:1px solid #d8d0be; border-radius:6px; padding:32px 40px; width:320px; }
  h2 { font-family:'Roboto',sans-serif; margin-bottom:20px; color:#1a1714; }
  input[type=password] { width:100%; padding:10px 13px; border:1px solid #d8d0be; border-radius:3px; font-size:14px; margin-bottom:12px; outline:none; }
  input[type=password]:focus { border-color:#b5401a; }
  button { width:100%; padding:11px; background:#b5401a; color:#fff; border:none; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; cursor:pointer; }
  .err { color:#b5401a; font-size:13px; margin-bottom:10px; }
</style></head>
<body>
<div class="box">
  <h2>Логи запитів</h2>
  <?php if (!empty($auth_error)) echo '<p class="err">Невірний пароль</p>'; ?>
  <form method="post">
    <input type="password" name="pwd" placeholder="Пароль" autofocus>
    <button type="submit">Увійти</button>
  </form>
</div>
</body></html>
<?php
  exit;
}

// Читаємо лог
$rows = [];
if (file_exists(LOG_FILE)) {
  $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach (array_reverse($lines) as $line) {
    $entry = parse_log_entry($line);
    if ($entry) $rows[] = $entry;
  }
}

// Фільтр по даті
$filter_date = $_GET['date'] ?? '';
if ($filter_date) {
  $rows = array_filter($rows, fn($r) => ($r['date'] ?? '') === $filter_date);
}

// Підсумки
$total_cost    = 0;
$total_inp     = 0;
$total_out     = 0;
$total_cache_r = 0;
$total_cache_w = 0;
$count         = 0;
foreach ($rows as $r) {
  $total_cost    += (float)($r['cost'] ?? 0);
  $total_inp     += (int)($r['inp'] ?? 0);
  $total_out     += (int)($r['out'] ?? 0);
  $total_cache_w += (int)($r['cache_write'] ?? 0);
  $total_cache_r += (int)($r['cache_read'] ?? 0);
  $count++;
}

$model_labels = [];
$settings = load_settings();
foreach (($settings['models'] ?? []) as $m) {
  if (!empty($m['id'])) {
    $model_labels[$m['id']] = $m['label'] ?? $m['id'];
  }
}

function fmtCost($usd) {
  if ($usd < 0.0001) return '< $0.0001';
  return '$' . number_format($usd, 4, '.', '');
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>Логи запитів</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { background:#f5f2eb; color:#1a1714; font-family:'Roboto',sans-serif; min-height:100vh; }
.hdr { background:#1a1714; color:#f5f2eb; padding:14px 32px; display:flex; align-items:center; justify-content:space-between; border-bottom:3px solid #b5401a; }
.hdr h1 { font-family:'Roboto',sans-serif; font-size:18px; }
.hdr a { color:#8a8278; font-family:'Roboto Mono',monospace; font-size:10px; letter-spacing:.1em; text-decoration:none; }
.hdr a:hover { color:#f5f2eb; }
.wrap { max-width:1200px; margin:0 auto; padding:28px 24px; }

.summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
.card { background:#fff; border:1px solid #e8e2d4; border-radius:4px; padding:16px 20px; }
.card-label { font-family:'Roboto Mono',monospace; font-size:9px; letter-spacing:.15em; text-transform:uppercase; color:#8a8278; margin-bottom:6px; }
.card-value { font-size:22px; font-weight:600; color:#1a1714; }
.card-value.red { color:#b5401a; }
.card-value.green { color:#2a5a30; }

.filters { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
.filters input[type=date] { padding:7px 12px; border:1px solid #d8d0be; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:12px; outline:none; background:#fff; }
.filters input[type=date]:focus { border-color:#b5401a; }
.filters button { padding:7px 16px; background:#b5401a; color:#fff; border:none; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:10px; letter-spacing:.1em; text-transform:uppercase; cursor:pointer; }
.filters a { font-family:'Roboto Mono',monospace; font-size:10px; color:#8a8278; text-decoration:none; letter-spacing:.1em; }
.filters a:hover { color:#b5401a; }

table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e8e2d4; border-radius:4px; overflow:hidden; font-size:12px; }
thead th { background:#1a1714; color:#f5f2eb; padding:10px 14px; font-family:'Roboto Mono',monospace; font-size:9px; letter-spacing:.15em; text-transform:uppercase; text-align:left; white-space:nowrap; }
tbody tr:nth-child(even) { background:#faf8f3; }
tbody tr:hover { background:#fff8f5; }
td { padding:9px 14px; border-bottom:1px solid #f0ece4; vertical-align:middle; white-space:nowrap; }
.tag { display:inline-block; padding:2px 7px; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:9px; letter-spacing:.08em; font-weight:500; }
.tag-web    { background:#e8f0fd; color:#3a5a9a; }
.tag-noweb  { background:#f5f2eb; color:#8a8278; }
.tag-hit    { background:#eaf3eb; color:#2a5a30; }
.tag-write  { background:#fff8e8; color:#8a6a20; }
.tag-nocache{ background:#f5f2eb; color:#8a8278; }
.cost-cell  { font-family:'Roboto Mono',monospace; font-weight:600; }
.empty { text-align:center; padding:40px; color:#8a8278; font-family:'Roboto Mono',monospace; font-size:12px; }
tbody tr { cursor:pointer; }
tr.expanded td { background:#fff8f5 !important; }
.row-detail { display:none; background:#faf5f0; }
.row-detail.open { display:table-row; }
.row-detail td { padding:14px 20px; border-bottom:2px solid #e8e2d4; }
.detail-grid { display:grid; grid-template-columns:120px 1fr; gap:4px 12px; font-size:12px; }
.detail-key { font-family:'Roboto Mono',monospace; font-size:10px; color:#8a8278; text-transform:uppercase; letter-spacing:.08em; padding-top:2px; }
.detail-val { font-family:'Roboto Mono',monospace; font-size:11px; word-break:break-all; }
.api-panel { background:#fff; border:1px solid #e8e2d4; border-radius:4px; padding:20px 24px; margin-top:24px; }
.api-panel-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.api-panel-title { font-family:'Roboto Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; font-weight:600; }
.api-entry { border:1px solid #e8e2d4; border-radius:3px; margin-bottom:10px; overflow:hidden; }
.api-entry-hdr { display:flex; gap:12px; align-items:center; padding:10px 14px; cursor:pointer; background:#faf8f3; }
.api-entry-hdr:hover { background:#f5f0e8; }
.api-entry-body { display:none; padding:12px 14px; background:#fff; border-top:1px solid #e8e2d4; }
.api-entry-body.open { display:block; }
.api-entry-body pre { font-family:'Roboto Mono',monospace; font-size:11px; white-space:pre-wrap; word-break:break-all; max-height:400px; overflow-y:auto; }
.tag-err { background:#fde8e8; color:#8e2d16; }
.tag-ok  { background:#eaf3eb; color:#2a5a30; }
.btn-load { padding:7px 14px; background:#1a1714; color:#fff; border:none; border-radius:3px; font-family:'Roboto Mono',monospace; font-size:10px; letter-spacing:.1em; cursor:pointer; }
.btn-load:hover { opacity:.85; }
</style>
</head>
<body>

<header class="hdr">
  <h1>Логи запитів</h1>
  <a href="?logout=1">&#10006; Вийти</a>
</header>

<div class="wrap">

  <!-- Summary -->
  <div class="summary">
    <div class="card">
      <div class="card-label">Запитів <?= $filter_date ? 'за день' : 'всього' ?></div>
      <div class="card-value"><?= $count ?></div>
    </div>
    <div class="card">
      <div class="card-label">Вартість</div>
      <div class="card-value red"><?= fmtCost($total_cost) ?></div>
    </div>
    <div class="card">
      <div class="card-label">Вхідних токенів</div>
      <div class="card-value"><?= number_format($total_inp, 0, '.', ' ') ?></div>
    </div>
    <div class="card">
      <div class="card-label">Вихідних токенів</div>
      <div class="card-value"><?= number_format($total_out, 0, '.', ' ') ?></div>
    </div>
    <div class="card">
      <div class="card-label">Cache hits</div>
      <div class="card-value green"><?= number_format($total_cache_r, 0, '.', ' ') ?></div>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="filters">
    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>">
    <button type="submit">Фільтрувати</button>
    <?php if ($filter_date): ?>
      <a href="/admin/log_viewer.php">Скинути</a>
    <?php endif; ?>
    <span style="font-family:'Roboto Mono',monospace;font-size:10px;color:#8a8278;margin-left:auto">
      Сьогодні: <?= date('Y-m-d') ?>
    </span>
  </form>

  <!-- Table -->
  <?php if (empty($rows)): ?>
    <div class="empty">Записів не знайдено</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>v</th>
        <th>Дата</th>
        <th>Час</th>
        <th>Модель</th>
        <th>Вхід tok</th>
        <th>Вихід tok</th>
        <th>Cache write</th>
        <th>Cache read</th>
        <th>Вартість</th>
        <th>Час (с)</th>
        <th>Довжина</th>
        <th>Пошук</th>
        <th>Кеш</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $idx => $r): ?>
      <?php
        $model_label = $model_labels[$r['model']] ?? $r['model'];
        $web_tag     = ($r['web'] ?? '') === 'web'
          ? '<span class="tag tag-web">веб</span>'
          : '<span class="tag tag-noweb">без</span>';
        $cache_status = $r['cache_status'] ?? '';
        if ($cache_status === 'cache-hit')
          $cache_tag = '<span class="tag tag-hit">&#10003; hit</span>';
        elseif ($cache_status === 'cache-write')
          $cache_tag = '<span class="tag tag-write">write</span>';
        else
          $cache_tag = '<span class="tag tag-nocache">—</span>';
        $cost = (float)($r['cost'] ?? 0);
        $cost_color = $cost > 0.05 ? 'color:#b5401a' : ($cost > 0.01 ? 'color:#8a6a20' : '');
      ?>
      <tr data-idx="<?= $idx ?>" class="log-row">
        <td style="font-family:'Roboto Mono',monospace"><?= (int)($r['v'] ?? 1) ?></td>
        <td><?= htmlspecialchars($r['date']) ?></td>
        <td style="font-family:'Roboto Mono',monospace"><?= htmlspecialchars($r['time']) ?></td>
        <td style="font-family:'Roboto Mono',monospace;font-size:11px"><?= htmlspecialchars($model_label) ?></td>
        <td style="font-family:'Roboto Mono',monospace"><?= number_format((int)($r['inp']??0), 0, '.', ' ') ?></td>
        <td style="font-family:'Roboto Mono',monospace"><?= number_format((int)($r['out']??0), 0, '.', ' ') ?></td>
        <td style="font-family:'Roboto Mono',monospace;color:#8a6a20"><?= (int)($r['cache_write']??0) > 0 ? number_format((int)$r['cache_write'], 0, '.', ' ') : '—' ?></td>
        <td style="font-family:'Roboto Mono',monospace;color:#2a5a30"><?= (int)($r['cache_read']??0) > 0 ? number_format((int)$r['cache_read'], 0, '.', ' ') : '—' ?></td>
        <td class="cost-cell" style="<?= $cost_color ?>"><?= fmtCost($cost) ?></td>
        <td style="font-family:'Roboto Mono',monospace;color:#8a8278"><?= htmlspecialchars($r['duration'] ?? '') ?></td>
        <td style="font-family:'Roboto Mono',monospace;color:#8a8278"><?= number_format((int)($r['prompt_len']??0), 0, '.', ' ') ?></td>
        <td><?= $web_tag ?></td>
        <td><?= $cache_tag ?></td>
      </tr>
      <tr class="row-detail" id="detail-<?= $idx ?>">
        <td colspan="13">
          <div class="detail-grid">
            <div class="detail-key">Модель (id)</div><div class="detail-val"><?= htmlspecialchars($r['model'] ?? '—') ?></div>
            <div class="detail-key">Провайдер</div><div class="detail-val"><?= htmlspecialchars($r['provider'] ?? '—') ?></div>
            <div class="detail-key">Дата / час</div><div class="detail-val"><?= htmlspecialchars(($r['date'] ?? '').' '.($r['time'] ?? '')) ?></div>
            <div class="detail-key">Вхід / вихід</div><div class="detail-val"><?= number_format((int)($r['inp']??0),0,'.','&nbsp;') ?> / <?= number_format((int)($r['out']??0),0,'.','&nbsp;') ?> tok</div>
            <div class="detail-key">Cache write</div><div class="detail-val"><?= (int)($r['cache_write']??0) > 0 ? number_format((int)$r['cache_write'],0,'.','&nbsp;').' tok' : '—' ?></div>
            <div class="detail-key">Cache read</div><div class="detail-val"><?= (int)($r['cache_read']??0) > 0 ? number_format((int)$r['cache_read'],0,'.','&nbsp;').' tok' : '—' ?></div>
            <div class="detail-key">Вартість</div><div class="detail-val"><?= fmtCost((float)($r['cost']??0)) ?></div>
            <div class="detail-key">Час відповіді</div><div class="detail-val"><?= htmlspecialchars($r['duration'] ?? '—') ?> с</div>
            <div class="detail-key">Довжина промту</div><div class="detail-val"><?= number_format((int)($r['prompt_len']??0),0,'.','&nbsp;') ?> симв.</div>
            <?php if (!empty($r['error'])): ?>
            <div class="detail-key" style="color:#8e2d16">Помилка</div><div class="detail-val" style="color:#8e2d16"><?= htmlspecialchars($r['error']) ?></div>
            <div class="detail-key">HTTP код</div><div class="detail-val"><?= (int)($r['code']??0) ?></div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

</div>

<div class="wrap" style="padding-top:0">
  <div class="api-panel">
    <div class="api-panel-hdr">
      <span class="api-panel-title">Останні API відповіді</span>
      <button class="btn-load" id="btn_load_api">Завантажити</button>
    </div>
    <div id="api_responses_list"><span style="font-family:'Roboto Mono',monospace;font-size:11px;color:#8a8278">Натисніть «Завантажити» для перегляду</span></div>
  </div>
</div>

<script>
// ── Розгортання рядків лога ───────────────────────────────────────────────────
document.querySelectorAll('.log-row').forEach(function(row) {
  row.addEventListener('click', function() {
    var idx    = this.getAttribute('data-idx');
    var detail = document.getElementById('detail-' + idx);
    if (!detail) return;
    var isOpen = detail.classList.contains('open');
    // close all
    document.querySelectorAll('.row-detail.open').forEach(function(d){ d.classList.remove('open'); });
    document.querySelectorAll('.log-row.expanded').forEach(function(r){ r.classList.remove('expanded'); });
    if (!isOpen) {
      detail.classList.add('open');
      this.classList.add('expanded');
    }
  });
});

// ── Завантаження API відповідей ───────────────────────────────────────────────
document.getElementById('btn_load_api').addEventListener('click', function() {
  var btn = this;
  btn.disabled = true;
  btn.textContent = 'Завантаження...';
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '/api/settings', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    btn.disabled = false;
    btn.textContent = 'Оновити';
    var container = document.getElementById('api_responses_list');
    try {
      var d = JSON.parse(xhr.responseText);
      if (!d.ok || !d.responses || !d.responses.length) {
        container.innerHTML = '<span style="font-family:'Roboto Mono',monospace;font-size:11px;color:#8a8278">Відповідей ще немає</span>';
        return;
      }
      var html = '';
      d.responses.forEach(function(r, i) {
        var isErr  = r.type === 'error';
        var tagCls = isErr ? 'tag-err' : 'tag-ok';
        var tagTxt = isErr ? ('Помилка ' + r.code) : ('OK ' + r.code);
        var body   = '';
        try { body = JSON.stringify(JSON.parse(r.body), null, 2); } catch(e) { body = r.body || ''; }
        html += '<div class="api-entry">'
              + '<div class="api-entry-hdr" data-api="' + i + '">'
              + '<span class="tag ' + tagCls + '">' + tagTxt + '</span>'
              + '<span style="font-family:'Roboto Mono',monospace;font-size:11px">' + esc(r.ts || '') + '</span>'
              + '<span style="font-family:'Roboto Mono',monospace;font-size:11px;color:#8a8278">' + esc(r.provider || '') + ' / ' + esc(r.model || '') + '</span>'
              + '</div>'
              + '<div class="api-entry-body" id="api-body-' + i + '"><pre>' + escHtml(body) + '</pre></div>'
              + '</div>';
      });
      container.innerHTML = html;
      container.querySelectorAll('.api-entry-hdr').forEach(function(hdr) {
        hdr.addEventListener('click', function() {
          var body = document.getElementById('api-body-' + this.getAttribute('data-api'));
          if (body) body.classList.toggle('open');
        });
      });
    } catch(e) {
      container.innerHTML = '<span style="color:#8e2d16">Помилка: ' + e.message + '</span>';
    }
  };
  xhr.onerror = function() { btn.disabled = false; btn.textContent = 'Оновити'; };
  xhr.send(JSON.stringify({action:'get_api_responses'}));
});

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>
<?php
// Закриваємо сесію
session_write_close();
