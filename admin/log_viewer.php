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
    <?php foreach ($rows as $r): ?>
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
      <tr>
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
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

</div>
</body>
</html>
<?php
// Закриваємо сесію
session_write_close();
