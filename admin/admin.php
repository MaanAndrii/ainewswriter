<?php
require_once __DIR__ . '/../core/app_settings.php';
require_once __DIR__ . '/../core/auth.php';

check_admin_access();

$pp = get_prompt_profile_user();
$errors = [];
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prompt_fields'])) {
    $fields = [
        'json_rule' => $_POST['pf_json_rule'] ?? '',
        'requirements_title' => $_POST['pf_requirements_title'] ?? '',
        'input_title' => $_POST['pf_input_title'] ?? '',
        'news_fields_on' => $_POST['pf_news_fields_on'] ?? '',
        'news_requirements_on' => $_POST['pf_news_requirements_on'] ?? '',
        'tone_prefix' => $_POST['pf_tone_prefix'] ?? '',
        'tone_short_rules' => $_POST['pf_tone_short_rules'] ?? '',
        'depth_prefix' => $_POST['pf_depth_prefix'] ?? '',
        'depth_short_rules' => $_POST['pf_depth_short_rules'] ?? '',
        'source_ref_rule' => $_POST['pf_source_ref_rule'] ?? '',
        'websearch_on' => $_POST['pf_websearch_on'] ?? '',
        'websearch_off' => $_POST['pf_websearch_off'] ?? '',
        'fb_checkbox_on' => $_POST['pf_fb_checkbox_on'] ?? '',
        'fb_style_rules' => $_POST['pf_fb_style_rules'] ?? '',
        'facebook_when_disabled' => $_POST['pf_facebook_when_disabled'] ?? 'omit',
        'lead_min_chars' => $_POST['lim_lead_min'] ?? 150,
        'lead_max_chars' => $_POST['lim_lead_max'] ?? 200,
    ];
    save_prompt_profile_user($fields);
    $status = 'Збережено ✔';
    $pp = get_prompt_profile_user();
}

function pp_str($arr, $key, $default = '') {
    return htmlspecialchars($arr[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

function pp_arr($arr, $key) {
    return implode("\n---\n", $arr[$key] ?? []);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Адмінка — Налаштування промтів</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 20px; background: #f8f5f0; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #b5401a; font-size: 24px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #4a4a4a; }
        .lbl { font-weight: 600; color: #4a4a4a; }
        .small { font-size: 12px; color: #8a8278; font-weight: 400; }
        textarea, input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #d8d0be; border-radius: 4px; font-family: inherit; font-size: 14px; background: #fff; }
        textarea { min-height: 80px; resize: vertical; }
        .btn-mini { padding: 8px 16px; background: #b5401a; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-mini:hover { background: #9a3412; }
        .status { margin-top: 10px; color: #2a5a30; font-size: 14px; }
        .error { color: #b5401a; }
        .section { margin-bottom: 30px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .section-title { font-size: 18px; color: #b5401a; margin-bottom: 15px; border-bottom: 1px solid #d8d0be; padding-bottom: 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <h1>Налаштування промтів</h1>
        <div class="section">
            <div class="section-title">Основні параметри</div>
            <div class="form-group">
                <label class="lbl">JSON-правило <span style="color:#A32D2D">*</span></label>
                <textarea id="pf_json_rule" rows="3"><?= pp_str($pp, 'json_rule') ?></textarea>
            </div>
            <div class="form-group">
                <label class="lbl">Заголовок блоку параметрів <span style="color:#A32D2D">*</span></label>
                <input type="text" id="pf_requirements_title" value="<?= pp_str($pp, 'requirements_title') ?>">
            </div>
            <div class="form-group">
                <label class="lbl">Заголовок вхідного матеріалу <span style="color:#A32D2D">*</span></label>
                <input type="text" id="pf_input_title" value="<?= pp_str($pp, 'input_title') ?>">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="lbl">Поля JSON при увімкненій новині <span style="color:#A32D2D">*</span></label>
                    <textarea id="pf_news_fields_on" rows="3"><?= pp_str($pp, 'news_fields_on') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="lbl">Вимоги до новини <span style="color:#A32D2D">*</span> <span class="small">— підтримує {{headlines_count}}, {{leads_count}}, {{article_max_chars}}, {{lead_min_chars}}, {{lead_max_chars}}</span></label>
                    <textarea id="pf_news_requirements_on" rows="3"><?= pp_str($pp, 'news_requirements_on') ?></textarea>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="lbl">Префікс тональності <span style="color:#A32D2D">*</span></label>
                    <textarea id="pf_tone_prefix" rows="2"><?= pp_str($pp, 'tone_prefix') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="lbl">Короткі описи тональностей <span style="color:#A32D2D">*</span> <span class="small">— 4 записи розділені <code>---</code></span></label>
                    <textarea id="pf_tone_short_rules" rows="4" style="font-family: monospace; font-size: 12px;"><?= pp_arr($pp, 'tone_short_rules') ?></textarea>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="lbl">Префікс глибини рерайту <span style="color:#A32D2D">*</span></label>
                    <textarea id="pf_depth_prefix" rows="2"><?= pp_str($pp, 'depth_prefix') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="lbl">Короткі інструкції глибини <span style="color:#A32D2D">*</span> <span class="small">— 4 записи розділені <code>---</code></span></label>
                    <textarea id="pf_depth_short_rules" rows="4" style="font-family: monospace; font-size: 12px;"><?= pp_arr($pp, 'depth_short_rules') ?></textarea>
                </div>
            </div>
            <div class="form-group">
                <label class="lbl">Інструкція для джерела <span class="small">— {{source_ref}} замінюється значенням з поля «Джерело новини»</span></label>
                <textarea id="pf_source_ref_rule" rows="3"><?= pp_str($pp, 'source_ref_rule') ?></textarea>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="lbl">Web-пошук увімкнено <span style="color:#A32D2D">*</span></label>
                    <textarea id="pf_websearch_on" rows="2"><?= pp_str($pp, 'websearch_on') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="lbl">Web-пошук вимкнено <span style="color:#A32D2D">*</span></label>
                    <textarea id="pf_websearch_off" rows="2"><?= pp_str($pp, 'websearch_off') ?></textarea>
                </div>
            </div>
            <div class="form-group">
                <label class="lbl">Facebook-допис увімкнено <span style="color:#A32D2D">*</span></label>
                <textarea id="pf_fb_checkbox_on" rows="2"><?= pp_str($pp, 'fb_checkbox_on') ?></textarea>
            </div>
            <div class="form-group">
                <label class="lbl">Стилі для Facebook <span style="color:#A32D2D">*</span> <span class="small">— 4 записи розділені <code>---</code></span></label>
                <textarea id="pf_fb_style_rules" rows="4" style="font-family: monospace; font-size: 12px;"><?= pp_arr($pp, 'fb_style_rules') ?></textarea>
            </div>
            <div class="form-group">
                <label class="lbl">Дія для Facebook, якщо вимкнено</label>
                <input type="text" id="pf_facebook_when_disabled" value="<?= pp_str($pp, 'facebook_when_disabled', 'omit') ?>">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="small">Мін. символів ліду</label>
                    <input type="number" id="lim_lead_min" min="50" max="500" value="<?= (int)($pp['lead_min_chars'] ?? 150) ?>">
                </div>
                <div class="form-group">
                    <label class="small">Макс. символів ліду</label>
                    <input type="number" id="lim_lead_max" min="50" max="500" value="<?= (int)($pp['lead_max_chars'] ?? 200) ?>">
                </div>
            </div>
            <div class="form-group">
                <button type="button" class="btn-mini danger" id="save_prompt_fields_btn">Зберегти складові промту</button>
                <span id="save_fields_status" class="status"><?= $status ?></span>
            </div>
        </div>
    </div>
    <script>
        function val(id) { return (document.getElementById(id) && document.getElementById(id).value || '').trim(); }
        function lines(id) {
            var raw = val(id);
            return raw.split(/\n?---\n?/).map(function(s){ return s.trim(); }).filter(Boolean);
        }

        var REQUIRED_PROMPT_FIELDS = {
            'pf_json_rule': 'JSON-правило',
            'pf_requirements_title': 'Заголовок блоку параметрів',
            'pf_input_title': 'Заголовок вхідного матеріалу',
            'pf_news_fields_on': 'Поля JSON при увімкненій новині',
            'pf_news_requirements_on': 'Вимоги до новини',
            'pf_tone_prefix': 'Префікс тональності',
            'pf_tone_short_rules': 'Короткі описи тональностей',
            'pf_depth_prefix': 'Префікс глибини рерайту',
            'pf_depth_short_rules': 'Короткі інструкції глибини',
            'pf_websearch_on': 'Web-пошук увімкнено',
            'pf_websearch_off': 'Web-пошук вимкнено'
        };

        function validatePromptFields() {
            var errors = [];
            for (var id in REQUIRED_PROMPT_FIELDS) {
                if (!val(id)) errors.push(REQUIRED_PROMPT_FIELDS[id]);
            }
            return errors;
        }

        function readPromptFields() {
            return {
                json_rule: val('pf_json_rule'),
                requirements_title: val('pf_requirements_title'),
                input_title: val('pf_input_title'),
                news_fields_on: val('pf_news_fields_on'),
                news_requirements_on: val('pf_news_requirements_on'),
                tone_prefix: val('pf_tone_prefix'),
                tone_short_rules: (function(){
                    var keys = ['neutral','intriguing','emotional','seo'];
                    var vals = lines('pf_tone_short_rules');
                    var m = {};
                    keys.forEach(function(k, i){ m[k] = vals[i] || ''; });
                    return m;
                }()),
                depth_prefix: val('pf_depth_prefix'),
                depth_short_rules: lines('pf_depth_short_rules'),
                source_ref_rule: val('pf_source_ref_rule'),
                websearch_on: val('pf_websearch_on'),
                websearch_off: val('pf_websearch_off'),
                fb_checkbox_on: val('pf_fb_checkbox_on'),
                fb_style_rules: lines('pf_fb_style_rules'),
                facebook_when_disabled: val('pf_facebook_when_disabled') || 'omit',
                lead_min_chars: val('lim_lead_min'),  // Виправлено: зчитування з lim_lead_min
                lead_max_chars: val('lim_lead_max')   // Виправлено: зчитування з lim_lead_max
            };
        }

        var save_prompt_fields_btn = document.getElementById('save_prompt_fields_btn');
        if (save_prompt_fields_btn) {
            save_prompt_fields_btn.addEventListener('click', function() {
                var fields = readPromptFields();
                var status = document.getElementById('save_fields_status');
                var errors = validatePromptFields();
                if (errors.length) {
                    status.textContent = 'Помилки: ' + errors.join(', ');
                    return;
                }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/api/settings', true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.onload = function() {
                    var d = {};
                    try { d = JSON.parse(xhr.responseText); } catch(e) {}
                    if (d.ok) {
                        status.textContent = 'Збережено ✔';
                        location.reload();  // Перезавантаження для оновлення налаштувань
                    } else {
                        status.textContent = 'Помилка: ' + (d.error || 'невідома');
                    }
                };
                xhr.onerror = function() {
                    status.textContent = 'Помилка мережі';
                };
                xhr.send(JSON.stringify({ action: 'save_prompt_profiles', profiles: { user: fields } }));  // Виправлено: дія save_prompt_profiles
            });
        }
    </script>
</body>
</html>
