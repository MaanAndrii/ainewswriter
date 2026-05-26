var PROXY_URL = '/api/proxy';
var MODEL_PRICES = {};
var MODEL_META = {};
var DEPTH_LABELS = ['Мінімальна', 'Помірна', 'Глибока', 'Повна переробка'];
var DEPTH_HINTS  = ['Зберігай формулювання', 'Перефразуй ~50%', 'Переписуй більшість', 'Лише факти'];
var DEPTH_THRESH = [10, 25, 45, 65];
var FB_STYLE_LABELS = ['Серйозний', 'Нейтральний', 'Дружній', 'Гумористичний'];
var FB_STYLE_HINTS  = ['Стримано, без емодзі', 'Збалансований тон', 'Теплий тон, помірні емодзі', 'Легкий гумор без токсичності'];
var TONE_LABELS  = { neutral: 'Нейтральний', intriguing: 'Інтригуючий', emotional: 'Емоційний', seo: 'SEO' };
var TONE_COLORS  = { 'Нейтральний': '#4a7fa5', 'Інтригуючий': '#8a4a9a', 'Емоційний': '#b5401a', 'SEO': '#2a5a30' };
var currentSource = '';
var copyStore = {};
var copyIdx = 0;
var SYSTEM_PROMPT_DEFAULT = '';
var PROMPT_PROFILES = {};
// ── Copy ──
function storeCopy(text) {
  var id = 'c' + (copyIdx++);
  copyStore[id] = text;
  return id;
}
function doCopy(btn, id) {
  var text = copyStore[id] || '';
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;font-size:12px';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  var ok = false;
  try { ok = document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
  if (!ok && navigator.clipboard) {
    navigator.clipboard.writeText(text).then(function () {
      showOk(btn);
    }).catch(function () {
      showFail(btn);
    });
    return;
  }
  if (ok) showOk(btn); else showFail(btn);
}
function showOk(btn) {
  btn.textContent = '\u2713 скопійовано';
  btn.className = 'copy-btn ok';
  setTimeout(function () { btn.textContent = 'копіювати'; btn.className = 'copy-btn'; }, 2000);
}
function showFail(btn) {
  btn.textContent = 'не вийшло';
  btn.className = 'copy-btn fail';
  setTimeout(function () { btn.textContent = 'копіювати'; btn.className = 'copy-btn'; }, 2000);
}
function makeCopyBtn(text) {
  var id = storeCopy(text);
  return '<button class="copy-btn" onclick="doCopy(this,\'' + id + '\')">копіювати</button>';
}
// ── Helpers ──
function esc(s) {
  return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function getVal(id)   { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
function getCheck(id) { var el = document.getElementById(id); return el ? el.checked : false; }
function getDepth()   { return parseInt(document.getElementById('depthSlider').value, 10); }
function getTone()    { var el = document.querySelector('input[name="tone"]:checked'); return el ? el.value : 'neutral'; }
function getFbStyle() { var el = document.getElementById('fbStyleSlider'); return el ? parseInt(el.value, 10) : 1; }
function getModel()      { var el = document.getElementById('modelSelect'); return el ? el.value : 'claude-haiku-4-5-20251001'; }
// ── Cost ──
function calcCost(inputTok, outputTok, model) {
  var p = MODEL_PRICES[model] || { inp: 3.0, out: 15.0 };
  return (inputTok * p.inp + outputTok * p.out) / 1000000;
}
function fmtCost(usd) {
  if (usd < 0.0001) return '< $0.0001';
  return '$' + usd.toFixed(4);
}
function showCost(usage, model, webSearchUsed) {
  var bar = document.getElementById('costBar');
  if (!bar || !usage) return;
  var p    = MODEL_PRICES[model] || {};
  var cost = calcCost(usage.input_tokens, usage.output_tokens, model);
  var wsLabel = webSearchUsed ? ' + веб-пошук 🔍' : '';
  bar.innerHTML = '<span class="cost-model">Модель: <strong>' + esc(p.label || model) + wsLabel + '</strong></span>'
    + '<span>Вхід: <strong>' + usage.input_tokens + ' токенів</strong></span>'
    + '<span>Вихід: <strong>' + usage.output_tokens + ' токенів</strong></span>'
    + '<span>Вартість запиту: <strong>' + fmtCost(cost) + '</strong></span>';
}
// ── Similarity (Jaccard) ──
function similarity(a, b) {
  if (!a || !b) return 0;
  function words(s) {
    var set = {}, arr = s.toLowerCase().replace(/[^\wа-яёіїєґ\s]/gi, '').split(/\s+/);
    for (var i = 0; i < arr.length; i++) if (arr[i]) set[arr[i]] = 1;
    return set;
  }
  var A = words(a), B = words(b), inter = 0, union = {};
  for (var k in A) { union[k] = 1; if (B[k]) inter++; }
  for (var k in B) union[k] = 1;
  var u = Object.keys(union).length;
  return u ? Math.round(inter / u * 100) : 0;
}
// ── Init UI ──
document.getElementById('depthSlider').addEventListener('input', function (e) {
  var v = parseInt(e.target.value, 10);
  document.getElementById('depthLabel').textContent = DEPTH_LABELS[v];
  document.getElementById('depthHint').textContent  = DEPTH_HINTS[v];
});
var toneInputs = document.querySelectorAll('.tone-opt input');
for (var _i = 0; _i < toneInputs.length; _i++) {
  toneInputs[_i].addEventListener('change', function () {
    var opts = document.querySelectorAll('.tone-opt');
    for (var j = 0; j < opts.length; j++) opts[j].classList.remove('active');
    this.closest('.tone-opt').classList.add('active');
  });
}
var fbCheckEl = document.getElementById('fbCheck');
var fbWrapEl = document.getElementById('fbStyleWrap');
var fbSliderEl = document.getElementById('fbStyleSlider');
function syncFbStyleUI() {
  if (!fbWrapEl || !fbCheckEl) return;
  fbWrapEl.style.display = fbCheckEl.checked ? 'block' : 'none';
}
if (fbSliderEl) {
  fbSliderEl.addEventListener('input', function (e) {
    var v = parseInt(e.target.value, 10);
    document.getElementById('fbStyleLabel').textContent = FB_STYLE_LABELS[v] || FB_STYLE_LABELS[1];
    document.getElementById('fbStyleHint').textContent = FB_STYLE_HINTS[v] || FB_STYLE_HINTS[1];
  });
}
if (fbCheckEl) fbCheckEl.addEventListener('change', function(){ syncFbStyleUI(); syncActionButtons(); });
syncFbStyleUI();
function syncActionButtons() {
  var canRun = getCheck('makeNews') || getCheck('fbCheck');
  var processBtn = document.getElementById('btnProcess');
  if (processBtn) processBtn.disabled = !canRun;
  var testBtn = document.querySelector('button[onclick="showPromptPreview()"]');
  if (testBtn) testBtn.disabled = !canRun;
}
syncActionButtons();
document.getElementById('makeNews').addEventListener('change', syncActionButtons);
document.getElementById('source').addEventListener('keydown', function (e) {
  if (e.ctrlKey && e.key === 'Enter') runProcess(null);
});
var PROV_VISUAL = {
  anthropic: { name: 'Claude',  abbr: 'Cl',  color: '#c27a52' },
  xai:       { name: 'xAI',    abbr: 'xAI', color: '#1a1a1a' },
  gemini:    { name: 'Gemini', abbr: 'G',   color: '#4285f4' },
  mistral:   { name: 'Mistral',abbr: 'Ms',  color: '#e07020' },
  openai:    { name: 'OpenAI', abbr: 'OA',  color: '#10a37f' },
  deepseek:  { name: 'Deep',   abbr: 'DS',  color: '#4d6bfe' },
  groq:      { name: 'Groq',   abbr: 'Gq',  color: '#f55036' }
};

function loadModelSettings() {
  var provPicker = document.getElementById('providerPicker');
  var mdlCatalog = document.getElementById('modelCatalog');

  function showModelError(msg) {
    if (provPicker) provPicker.innerHTML = '';
    if (mdlCatalog) mdlCatalog.innerHTML =
      '<span style="font-size:11px;color:#b5401a;font-family:\'Roboto Mono\',monospace">' +
      msg + ' <button onclick="loadModelSettings()" style="margin-left:6px;font-size:11px;' +
      'border:1px solid #b5401a;background:none;color:#b5401a;border-radius:3px;padding:2px 7px;cursor:pointer">↺</button></span>';
  }

  if (provPicker) provPicker.innerHTML = '';
  if (mdlCatalog) mdlCatalog.innerHTML =
    '<span style="font-size:11px;color:#8a8278;font-family:\'Roboto Mono\',monospace">Завантаження…</span>';

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '/api/settings?_=' + Date.now(), true);
  xhr.timeout = 10000;
  xhr.ontimeout = function() { showModelError('Timeout — сервер не відповідає'); };
  xhr.onerror   = function() { showModelError('Помилка мережі'); };
  xhr.onload = function () {
    if (xhr.status !== 200) { showModelError('HTTP ' + xhr.status); return; }
    var d = {};
    try { d = JSON.parse(xhr.responseText); } catch (e) { showModelError('Помилка відповіді сервера'); return; }

    if (!d.models || !d.models.length) { showModelError('Моделі не налаштовано — додайте в адмінці'); return; }

    MODEL_PRICES = {};
    MODEL_META = {};

    var byProvider = {};
    for (var i = 0; i < d.models.length; i++) {
      var m = d.models[i];
      MODEL_PRICES[m.id] = { inp: Number(m.inp || 3), out: Number(m.out || 15), label: m.label || m.id };
      MODEL_META[m.id] = { provider: m.provider || '' };
      if (m.enabled === false || m.enabled === 0 || m.enabled === 'false') continue;
      var p = m.provider || 'unknown';
      if (!byProvider[p]) byProvider[p] = [];
      byProvider[p].push(m);
    }

    SYSTEM_PROMPT_DEFAULT = d.prompt_system || '';
    PROMPT_PROFILES = d.prompt_profiles || {};

    if (!provPicker || !mdlCatalog || !document.getElementById('modelSelect')) {
      showModelError('DOM error — перезавантажте сторінку');
      return;
    }

    var provKeys = Object.keys(byProvider);
    if (!provKeys.length) { showModelError('Всі моделі вимкнені — увімкніть в адмінці'); return; }

    var hiddenSel = document.getElementById('modelSelect');
    var initModel    = d.default_model || '';
    var initProvider = (initModel && MODEL_META[initModel]) ? MODEL_META[initModel].provider : '';
    if (!initProvider || !byProvider[initProvider]) {
      initProvider = provKeys[0];
      initModel    = byProvider[initProvider][0].id;
    }
    hiddenSel.value = initModel;

    function renderModels(prov, selId) {
      mdlCatalog.innerHTML = '';
      (byProvider[prov] || []).forEach(function(mdl) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mdl-btn' + (mdl.id === selId ? ' active' : '');
        btn.textContent = mdl.label || mdl.id;
        btn.addEventListener('click', function() {
          hiddenSel.value = mdl.id;
          mdlCatalog.querySelectorAll('.mdl-btn').forEach(function(b) { b.classList.remove('active'); });
          btn.classList.add('active');
        });
        mdlCatalog.appendChild(btn);
      });
    }

    var curProvider = initProvider;
    provPicker.innerHTML = '';
    provKeys.forEach(function(prov) {
      var vis = PROV_VISUAL[prov] || { name: prov, abbr: prov.slice(0,2).toUpperCase(), color: '#888888' };
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'prov-btn' + (prov === initProvider ? ' active' : '');
      btn.innerHTML =
        '<div class="prov-icon" style="background:' + vis.color + '">' + vis.abbr + '</div>' +
        '<div class="prov-name">' + vis.name + '</div>';
      btn.addEventListener('click', function() {
        if (curProvider === prov) return;
        curProvider = prov;
        provPicker.querySelectorAll('.prov-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var first = (byProvider[prov] || [])[0];
        if (first) { hiddenSel.value = first.id; renderModels(prov, first.id); }
      });
      provPicker.appendChild(btn);
    });

    renderModels(initProvider, initModel);
  };
  xhr.send();
}
loadModelSettings();
// ── Prompt profile merge ──
function normalizePromptText(value) {
  if (typeof value !== 'string') return value;
  return value
    .replace(/\\\\n/g, '\n')
    .replace(/\\n/g, '\n');
}
function normalizePromptProfile(profile) {
  if (!profile || typeof profile !== 'object') return profile;
  for (var k in profile) {
    if (!Object.prototype.hasOwnProperty.call(profile, k)) continue;
    var v = profile[k];
    if (typeof v === 'string') profile[k] = normalizePromptText(v);
    else if (Array.isArray(v)) {
      for (var i = 0; i < v.length; i++) if (typeof v[i] === 'string') v[i] = normalizePromptText(v[i]);
    }
  }
  return profile;
}
// ── Build prompt ──
function buildPrompt(source, sourceRef, extra, fbCheck, fbStyle, tone, makeNews, depth, regenInstruction) {
  var profile = (PROMPT_PROFILES && PROMPT_PROFILES.user) ? normalizePromptProfile(PROMPT_PROFILES.user) : {};
  if (!profile || !profile.json_rule || !profile.requirements_title) {
    throw new Error('Prompt profile is not configured. Open admin and save prompt JSON.');
  }
  var toneLabel  = TONE_LABELS[tone] || 'Нейтральний';
  var toneShortMap = profile.tone_short_rules || {};
  var toneShort = toneShortMap[tone] || toneLabel;

  var extraBlock = extra            ? '\n\nДодаткові інструкції / контекст:\n' + extra : '';
  var regenBlock = regenInstruction ? '\n\nІНСТРУКЦІЇ ДЛЯ ПЕРЕГЕНЕРАЦІЇ:\n' + regenInstruction : '';
  var refPrompt  = sourceRef
    ? String(profile.source_ref_rule || '').replaceAll('{{source_ref}}', sourceRef)
    : '';
  var depthShortRules = Array.isArray(profile.depth_short_rules) ? profile.depth_short_rules : [];
  var depthShort = depthShortRules[depth] || '';
  var depthInstr = String(profile.depth_prefix || '').replace('{{depth_short}}', depthShort);
  var toneInstr = String(profile.tone_prefix || '').replace('{{tone_label}}', toneLabel).replace('{{tone_short}}', toneShort);
  var newsFields = '';
  var newsReqs = '';
  if (makeNews) {
    newsFields = profile.news_fields_on || '';
    newsReqs   = String(profile.news_requirements_on || '')
      .replaceAll('{{tone_label}}', toneLabel)
      .replaceAll('{{headlines_count}}', String(profile.headlines_count || 4))
      .replaceAll('{{leads_count}}', String(profile.leads_count || 2))
      .replaceAll('{{article_max_chars}}', String(profile.article_max_chars || 3000))
      .replaceAll('{{lead_min_chars}}', String(profile.lead_min_chars || 150))
      .replaceAll('{{lead_max_chars}}', String(profile.lead_max_chars || 180));
  }
  var fbStyleRules = Array.isArray(profile.fb_style_rules) ? profile.fb_style_rules : [];
  var fbStyleRule = fbStyleRules[fbStyle] || fbStyleRules[1] || '';
  var fbLine = fbCheck
    ? String(profile.fb_checkbox_on || '').replaceAll('{{facebook_max_chars}}', String(profile.facebook_max_chars || 400)).replaceAll('{{fb_style_rule}}', fbStyleRule)
    : '';
  var jsonFacebookField = fbCheck ? '\n  "facebook": "..."' : '';
  var jsonNewsFields = newsFields;
  if (jsonNewsFields && jsonFacebookField) jsonNewsFields = jsonNewsFields.replace(/[,\s]+$/, '') + ',';
  return profile.json_rule + '\n{\n' + jsonNewsFields + jsonFacebookField + '\n}\n\n'
    + profile.requirements_title + '\n'
    + (newsReqs ? ('- ' + newsReqs + '\n') : '')
    + (makeNews ? ('- ' + toneInstr + '\n') : '')
    + (makeNews ? ('- ' + depthInstr + '\n') : '')
    + (refPrompt ? ('- ' + refPrompt + '\n') : '')
    + (fbLine ? ('- ' + fbLine + '\n') : '')
    + extraBlock + regenBlock + '\n'
    + (profile.input_title || 'ВХІДНИЙ МАТЕРІАЛ:') + '\n' + source;
}
// ── History ──
function showHistoryPanel() {
  var modal = document.getElementById('historyModal');
  if (modal) { modal.style.display = 'flex'; loadHistory(1); }
}
function closeHistoryModal() {
  var m = document.getElementById('historyModal');
  if (m) m.style.display = 'none';
}
function loadHistory(page) {
  var listEl  = document.getElementById('historyList');
  var pagerEl = document.getElementById('historyPager');
  if (!listEl) return;
  listEl.innerHTML = '<div style="color:#8a8278;font-family:\'Roboto Mono\',monospace;font-size:11px">Завантаження…</div>';

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '/api/settings', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    var d = {};
    try { d = JSON.parse(xhr.responseText); } catch(e) {
      listEl.innerHTML = '<div style="color:#b5401a;font-size:12px">Помилка розбору відповіді (HTTP ' + xhr.status + ')</div>';
      console.error('History parse error:', xhr.status, xhr.responseText.substring(0, 300));
      return;
    }
    if (!d.ok) {
      listEl.innerHTML = '<div style="color:#b5401a;font-size:12px">' + esc(d.error || 'Помилка (ok=false)') + '</div>';
      return;
    }
    if (!d.items || !d.items.length) {
      listEl.innerHTML = '<div style="color:#8a8278;font-family:\'Roboto Mono\',monospace;font-size:11px">Історія порожня</div>';
      if (pagerEl) pagerEl.innerHTML = '';
      return;
    }
    var html = '';
    for (var i = 0; i < d.items.length; i++) {
      var item = d.items[i];
      var dt = item.created_at ? item.created_at.replace('T', ' ').replace(/\+.*$/, '') : '';
      var src = item.source_ref ? ' · ' + esc(item.source_ref) : '';
      var cost = item.cost ? ' · $' + Number(item.cost).toFixed(4) : '';
      var preview = item.input_preview || item.output_preview || '';
      html += '<div class="history-item" data-id="' + item.id + '" onclick="loadGenerationById(' + item.id + ')">'
        + '<div class="history-item-meta">'
        + '<span class="history-item-date">' + esc(dt) + '</span>'
        + '<span class="history-item-model">' + esc(item.model || '') + '</span>'
        + esc(src) + esc(cost)
        + '</div>'
        + (preview ? '<div class="history-item-preview">' + esc(preview.substring(0, 120)) + (preview.length > 120 ? '…' : '') + '</div>' : '')
        + '</div>';
    }
    listEl.innerHTML = html;

    // Pager
    if (pagerEl) {
      var total = d.total || 0;
      var limit = 20;
      var pages = Math.ceil(total / limit);
      var cur   = d.page || 1;
      var pHtml = '<span>Сторінка ' + cur + ' з ' + pages + ' (' + total + ' записів)</span>';
      if (cur > 1) pHtml += ' <button class="btn-sm" style="margin-top:0" onclick="loadHistory(' + (cur-1) + ')">&#8592; Назад</button>';
      if (cur < pages) pHtml += ' <button class="btn-sm" style="margin-top:0" onclick="loadHistory(' + (cur+1) + ')">Далі &#8594;</button>';
      pagerEl.innerHTML = pHtml;
    }
  };
  xhr.onerror = function() {
    listEl.innerHTML = '<div style="color:#b5401a;font-size:12px">Помилка мережі</div>';
  };
  xhr.send(JSON.stringify({action: 'get_history', page: page}));
}
function loadGenerationById(id) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '/api/settings', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    var d = {};
    try { d = JSON.parse(xhr.responseText); } catch(e) {}
    if (!d.ok || !d.item) return;
    var item = d.item;
    // Populate input fields
    var srcEl = document.getElementById('source');
    var refEl = document.getElementById('sourceRef');
    if (srcEl && item.input_text) srcEl.value = item.input_text;
    if (refEl && item.source_ref) refEl.value = item.source_ref;
    // Display raw output JSON in output area
    var output = document.getElementById('output');
    if (output && item.output_json) {
      var raw = item.output_json;
      // Try to parse and render, otherwise show raw
      try {
        var clean = stripMarkdownWrapper(raw);
        var jsonText = extractFirstJsonObject(clean);
        if (jsonText) {
          var safeJsonText = jsonText.replace(/"(?:[^"\\]|\\.)*"/g, function(m) {
            return m.replace(/\n/g, '\\n').replace(/\r/g, '\\r').replace(/\t/g, '\\t');
          });
          var parsed = JSON.parse(safeJsonText);
          parsed._model = item.model || '';
          if (item.input_tokens || item.output_tokens) {
            parsed._usage = {input_tokens: item.input_tokens || 0, output_tokens: item.output_tokens || 0};
          }
          if (parsed._usage) showCost(parsed._usage, parsed._model);
          renderResults(parsed, item.input_text || '', true, !!parsed.facebook, 2);
        } else {
          output.innerHTML = '<div class="art-block"><div class="art-text">' + esc(raw) + '</div></div>';
        }
      } catch(e) {
        output.innerHTML = '<div class="art-block"><div class="art-text">' + esc(raw) + '</div></div>';
      }
    }
    closeHistoryModal();
  };
  xhr.send(JSON.stringify({action: 'get_generation', id: id}));
}

// ── API call via XHR ──
function hasMeaningfulContent(parsed, expectNews, expectFacebook) {
  if (!parsed || typeof parsed !== 'object') return false;
  if (expectNews) {
    if ((parsed.article || '').trim() !== '') return true;
    var heads = Array.isArray(parsed.headlines) ? parsed.headlines : [];
    for (var i = 0; i < heads.length; i++) {
      if ((heads[i] && heads[i].text || '').trim() !== '') return true;
    }
    var leads = Array.isArray(parsed.leads) ? parsed.leads : [];
    for (var j = 0; j < leads.length; j++) {
      if ((leads[j] && leads[j].text || '').trim() !== '') return true;
    }
  }
  if (expectFacebook && (parsed.facebook || '').trim() !== '') return true;
  return false;
}
function stripMarkdownWrapper(text) {
  // Remove markdown code fences including variants like **```json, *```json, ```json, ```
  var s = text
    .replace(/\*{0,3}`{3}[a-zA-Z]*/g, '')  // opening: **```json  *```json  ```json
    .replace(/`{3}\*{0,3}/g, '')             // closing: ```  ```**
    .replace(/[""]/g, '"')
    .replace(/['']/g, "'")
    .trim();
  return s;
}

function extractFirstJsonObject(text) {
  if (!text) return null;
  var inString = false;
  var escaped = false;
  var depth = 0;
  var start = -1;
  for (var i = 0; i < text.length; i++) {
    var ch = text[i];
    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (ch === '\\') {
        escaped = true;
      } else if (ch === '"') {
        inString = false;
      }
      continue;
    }
    if (ch === '"') {
      inString = true;
      continue;
    }
    if (ch === '{') {
      if (depth === 0) start = i;
      depth++;
      continue;
    }
    if (ch === '}') {
      if (depth > 0) depth--;
      if (depth === 0 && start !== -1) return text.slice(start, i + 1);
    }
  }
  return null;
}
function callAPI(prompt, model, systemPromptOverride, expectNews, expectFacebook, attempt, resolve, reject) {
  attempt = attempt || 1;
  var extra = attempt > 1 ? '\n\nКРИТИЧНО: поверни ВИКЛЮЧНО валідний JSON, починай з {' : '';
  var body = JSON.stringify({
    prompt: prompt + extra,
    model: model,
    systemPromptOverride: systemPromptOverride || '',
    source: getVal('source'),
    sourceRef: getVal('sourceRef'),
    stream: 1
  });

  var accText = '';
  var metaReceived = null;

  var output = document.getElementById('output');
  var streamBox = document.createElement('div');
  streamBox.className = 'stream-preview';
  var cursor = document.createElement('div');
  cursor.className = 'stream-cursor';
  streamBox.appendChild(cursor);
  output.innerHTML = '';
  output.appendChild(streamBox);

  function processChunk(raw) {
    if (!raw || raw.indexOf('data: ') !== 0) return true;
    var evPayload = raw.substring(6);
    if (evPayload === '[DONE]') return true;
    try {
      var ev = JSON.parse(evPayload);
      if (ev.error) { reject(new Error(ev.error)); return false; }
      if (ev.reset) { accText = ''; streamBox.textContent = ''; return true; }
      if (ev.meta)  { metaReceived = ev; return true; }
      if (ev.delta != null) {
        accText += ev.delta;
        var preview = accText.length > 600 ? '…' + accText.slice(-600) : accText;
        streamBox.textContent = preview;
        var nc = document.createElement('div');
        nc.className = 'stream-cursor';
        streamBox.appendChild(nc);
      }
    } catch(e) {}
    return true;
  }

  function startPolling(jobId) {
    var afterId  = 0;
    var maxWait  = 300000; // 5 хвилин
    var elapsed  = 0;
    var interval = 400;
    var stopped  = false;

    function poll() {
      if (stopped) return;
      fetch('/api/job_poll?id=' + encodeURIComponent(jobId) + '&after=' + afterId + '&_=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (stopped) return;
          if (data.error) { stopped = true; output.innerHTML = ''; reject(new Error(data.error)); return; }

          var chunks = data.chunks || [];
          for (var i = 0; i < chunks.length; i++) {
            if (!processChunk(chunks[i])) { stopped = true; return; }
          }
          if (data.next_after !== undefined) afterId = data.next_after;

          var status = data.status || '';
          if (status === 'done' || status === 'failed') {
            if (!stopped) { stopped = true; onComplete(); }
            return;
          }

          elapsed += interval;
          if (elapsed >= maxWait) {
            stopped = true;
            output.innerHTML = '';
            reject(new Error('Перевищено час очікування відповіді'));
            return;
          }
          setTimeout(poll, interval);
        })
        .catch(function(err) {
          if (stopped) return;
          stopped = true;
          output.innerHTML = '';
          reject(new Error(err.message || 'Помилка мережі'));
        });
    }

    poll();
  }

  function onComplete() {
    output.innerHTML = '';

    if (!accText.trim()) {
      reject(new Error('Порожня відповідь від API'));
      return;
    }

    var raw = accText;
    raw = raw.replace(/<cite[^>]*>([\s\S]*?)<\/cite>/g, '$1').replace(/<cite[^>]*>/g, '').replace(/<\/cite>/g, '');
    var clean = stripMarkdownWrapper(raw);
    var jsonText = extractFirstJsonObject(clean);

    if (!jsonText) {
      if (attempt < 3) return callAPI(prompt, model, systemPromptOverride, expectNews, expectFacebook, attempt + 1, resolve, reject);
      return reject(new Error('Модель не повернула JSON. Спробуй ще раз.'));
    }

    try {
      var safeJsonText = jsonText.replace(/"(?:[^"\\]|\\.)*"/g, function(m) {
        return m.replace(/\n/g, '\\n').replace(/\r/g, '\\r').replace(/\t/g, '\\t');
      });
      var parsed = JSON.parse(safeJsonText);

      if (!hasMeaningfulContent(parsed, expectNews, expectFacebook)) {
        if (attempt < 3) return callAPI(prompt, model, systemPromptOverride, expectNews, expectFacebook, attempt + 1, resolve, reject);
        return reject(new Error('Модель повернула порожній JSON без тексту. Спробуй іншу модель.'));
      }

      if (metaReceived) {
        parsed._usage = metaReceived.usage || null;
        parsed._webSearchUsed = !!metaReceived.web_search_used;
      }
      parsed._model = model;
      resolve(parsed);
    } catch(e) {
      if (attempt < 3) return callAPI(prompt, model, systemPromptOverride, expectNews, expectFacebook, attempt + 1, resolve, reject);
      reject(new Error('Помилка розбору відповіді. Спробуй ще раз.'));
    }
  }

  // Step 1: submit async job
  fetch('/api/job_submit', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: body
  }).then(function(response) {
    if (!response.ok) {
      return response.json().then(function(err) {
        throw new Error(err.error || 'HTTP ' + response.status);
      }).catch(function() {
        throw new Error('HTTP ' + response.status);
      });
    }
    return response.json();
  }).then(function(result) {
    if (!result || !result.ok || !result.job_id) {
      throw new Error((result && result.error) || 'Не вдалось створити завдання');
    }
    // Step 2: poll for job output
    startPolling(result.job_id);
  }).catch(function(err) {
    output.innerHTML = '';
    reject(new Error(err.message || 'Помилка мережі'));
  });
}
function closePromptModal(){
  var m=document.getElementById('promptModal');
  if(m) m.style.display='none';
}
function showPromptPreview(){
  var source    = getVal('source');
  var sourceRef = getVal('sourceRef');
  var extra     = getVal('extra');
  var makeNews  = getCheck('makeNews');
  var fbCheck   = getCheck('fbCheck');
  var tone      = getTone();
  var fbStyle   = getFbStyle();
  var depth     = getDepth();
  if (!source) { alert('Додайте вхідний матеріал'); return; }
  var prompt = buildPrompt(source, sourceRef, extra, fbCheck, fbStyle, tone, makeNews, depth, null);
  var sys = normalizePromptText(SYSTEM_PROMPT_DEFAULT);
  document.getElementById('promptPreview').textContent = (sys ? (sys + '\n\n') : '') + prompt;

  document.getElementById('promptModal').style.display = 'flex';
}
// ── Process ──
function runProcess(regenInstruction) {
  var source    = getVal('source');
  var sourceRef = getVal('sourceRef');
  var extra     = getVal('extra');
  var makeNews  = getCheck('makeNews');
  var fbCheck   = getCheck('fbCheck');
  var tone      = getTone();
  var fbStyle   = getFbStyle();
  var depth     = getDepth();
  var model     = getModel();
  if (!source) { alert('Додайте вхідний матеріал'); return; }
  currentSource = source;
  var isRegen = !!regenInstruction;
  var output  = document.getElementById('output');
  if (!isRegen && !confirm('Підтвердити відправку тексту в обробку?')) return;
  if (!isRegen) {
    setBtn('btnProcess', 'spinProcess', 'btnProcessText', true, 'Обробляю\u2026');
    output.innerHTML = '<div class="empty"><div class="empty-text" style="color:#b5401a">Генерую публікацію\u2026</div></div>';
    document.getElementById('costBar').innerHTML = '';
  } else {
    setBtn('btnRegen', 'spinRegen', 'regenBtnLbl', true, 'Перегенеровую\u2026');
  }
  var prompt = buildPrompt(source, sourceRef, extra, fbCheck, fbStyle, tone, makeNews, depth, regenInstruction);
  var systemPromptOverride = getVal('systemPromptOverride');
  callAPI(prompt, model, systemPromptOverride, makeNews, fbCheck, 1,
    function (data) {
      if (data._usage) showCost(data._usage, data._model || model, data._webSearchUsed);
      copyStore = {}; copyIdx = 0;
      renderResults(data, source, makeNews, fbCheck, depth);
      setBtn('btnProcess', 'spinProcess', 'btnProcessText', false, '\u25BA Обробити матеріал');
      setBtn('btnRegen',   'spinRegen',   'regenBtnLbl',    false, '\u21BA Застосувати правки');
    },
    function (err) {
      output.innerHTML = '<div class="err-box"><strong>\u26A0 Помилка:</strong> ' + esc(err.message) + '</div>';
      setBtn('btnProcess', 'spinProcess', 'btnProcessText', false, '\u25BA Обробити матеріал');
      setBtn('btnRegen',   'spinRegen',   'regenBtnLbl',    false, '\u21BA Застосувати правки');
    }
  );
}
function doDeepen() {
  var source    = currentSource || getVal('source');
  var sourceRef = getVal('sourceRef');
  var extra     = getVal('extra');
  var makeNews  = getCheck('makeNews');
  var fbCheck   = getCheck('fbCheck');
  var tone      = getTone();
  var fbStyle   = getFbStyle();
  var depth     = Math.min(getDepth() + 1, 3);
  var model     = getModel();
  var btn = document.getElementById('btnDeepen');
  var sp  = document.getElementById('spinDeepen');
  if (btn) btn.disabled = true;
  if (sp)  sp.style.display = 'block';
  var prompt = buildPrompt(source, sourceRef, extra, fbCheck, fbStyle, tone, makeNews, depth,
    'Текст недостатньо перероблено. Зроби значно глибший рерайт — переформулюй більшість речень, змінюй структуру, використовуй синоніми. Мінімум 20% змін.');
  callAPI(prompt, model, getVal('systemPromptOverride'), makeNews, fbCheck, 1,
    function (data) {
      if (data._usage) showCost(data._usage, data._model || model, data._webSearchUsed);
      copyStore = {}; copyIdx = 0;
      renderResults(data, source, makeNews, fbCheck, depth);
    },
    function (err) {
      alert('Помилка: ' + err.message);
      if (btn) btn.disabled = false;
      if (sp)  sp.style.display = 'none';
    }
  );
}
function setBtn(btnId, spId, lblId, on, text) {
  var btn = document.getElementById(btnId);
  var sp  = document.getElementById(spId);
  var lbl = document.getElementById(lblId);
  if (btn) btn.disabled = on;
  if (sp)  sp.style.display = on ? 'block' : 'none';
  if (lbl && text) lbl.textContent = text;
}
function resetAll() {
  document.getElementById('source').value    = '';
  document.getElementById('sourceRef').value = '';
  document.getElementById('extra').value     = '';
  document.getElementById('makeNews').checked = true;
  document.getElementById('fbCheck').checked   = false;
  document.getElementById('fbStyleSlider').value = 1;
  document.getElementById('fbStyleLabel').textContent = FB_STYLE_LABELS[1];
  document.getElementById('fbStyleHint').textContent = FB_STYLE_HINTS[1];
  syncFbStyleUI();
  syncActionButtons();
  document.getElementById('depthSlider').value = 2;
  document.getElementById('depthLabel').textContent = DEPTH_LABELS[2];
  document.getElementById('depthHint').textContent  = DEPTH_HINTS[2];
  document.querySelector('input[name="tone"][value="neutral"]').checked = true;
  var opts = document.querySelectorAll('.tone-opt');
  for (var i = 0; i < opts.length; i++) opts[i].classList.remove('active');
  document.querySelector('.tone-opt[data-key="neutral"]').classList.add('active');
  document.getElementById('costBar').innerHTML = '';
  document.getElementById('output').innerHTML = '<div class="empty"><div class="empty-icon">&#10022;</div><div class="empty-text">Результат z\'явиться тут</div></div>';
  currentSource = '';
  copyStore = {}; copyIdx = 0;
}
// ── Render ──
function renderResults(data, source, makeNews, fbCheck, depth) {
  var article  = data.article || '';
  var chg      = 100 - similarity(source, article);
  var charLen  = article.length;
  var minThr   = 20;
  var tgtThr   = DEPTH_THRESH[depth] !== undefined ? DEPTH_THRESH[depth] : 45;
  var belowMin = chg < minThr;
  var belowTgt = chg < tgtThr;
  var simColor = belowMin ? '#b5401a' : chg >= 60 ? '#2a5a30' : chg >= 35 ? '#8a6a20' : '#4a7fa5';
  var simLabel = belowMin ? 'замало змін!' : chg >= 60 ? 'глибокий рерайт' : chg >= 35 ? 'частковий рерайт' : 'близько до оригіналу';
  var deepLbl  = belowMin ? '\u26A0 Поглибити (нижче мінімуму)' : '\u2191 Поглибити рерайт';
  var html = '<div class="results">';
  // Headlines
  if (makeNews) {
    html += '<div><div class="sec-title">Заголовки</div><div class="h-grid">';
    var heads = data.headlines || [];
    for (var i = 0; i < heads.length; i++) {
      var h = heads[i];
      html += '<div class="h-card">'
        + '<div class="h-tag" style="color:' + (TONE_COLORS[h.tone] || '#8a8278') + '">' + esc(h.tone) + '</div>'
        + '<div class="h-text">' + esc(h.text) + '</div>'
        + makeCopyBtn(h.text)
        + '</div>';
    }
    html += '</div></div>';
  }
  // Leads
  if (makeNews) {
    html += '<div><div class="sec-title">Ліди</div>';
    var leads = data.leads || [];
    for (var i = 0; i < leads.length; i++) {
      var l   = leads[i];
      var len = (l.text || '').length;
      var lok = len >= 150 && len <= 200;
      var lc  = lok ? '#2a5a30' : '#8a6a20';
      var lt  = lok ? '\u2713' : (len < 150 ? '(замало, норма 150-200)' : '(забагато, норма 150-200)');
      html += '<div class="lead-card">'
        + '<div class="lead-text">' + esc(l.text) + '</div>'
        + '<div class="lead-len" style="color:' + lc + '">' + len + ' символів ' + lt + '</div>'
        + makeCopyBtn(l.text)
        + '</div>';
    }
    html += '</div>';
  }
  // Article + similarity
  if (makeNews) {
    var simBar = '<div class="sim-block">'
      + '<div class="sim-row">'
      + '<span style="font-family:monospace;font-size:10px;color:#8a8278">Змінено тексту:</span>'
      + '<span class="sim-pct" style="background:' + simColor + '18;border:1px solid ' + simColor + '44;color:' + simColor + '">' + chg + '%</span>'
      + '<span class="sim-lbl" style="color:' + simColor + '">' + simLabel + '</span>'
      + '</div>'
      + '<div class="sim-bar-wrap">'
      + '<div class="sim-bar" style="width:' + Math.min(chg, 100) + '%;background:' + simColor + '"></div>'
      + '<div class="sim-mark" style="background:#b5401a55;left:' + minThr + '%" title="Мінімум 20%"></div>'
      + '<div class="sim-mark" style="background:#8a827855;left:' + tgtThr + '%" title="Ціль глибини"></div>'
      + '</div>'
      + '<div class="sim-scale"><span>0%</span><span style="color:#b5401a88">\u25B2 мін 20%</span><span>100%</span></div>'
      + (belowTgt ? '<button id="btnDeepen" class="btn-sm" onclick="doDeepen()"><div class="spin sm" id="spinDeepen"></div>' + deepLbl + '</button>' : '')
      + '</div>';
    html += '<div><div class="sec-title">Текст новини</div>'
      + '<div class="art-block">'
      + '<div class="art-text">' + esc(article) + '</div>'
      + '<div class="char-row" style="color:' + (charLen > 3000 ? '#b5401a' : '#8a8278') + '">' + charLen + ' / 3000 символів' + (charLen > 3000 ? ' \u2014 перевищено!' : '') + '</div>'
      + simBar
      + makeCopyBtn(article)
      + '</div></div>';
  }
  // Facebook
  if (fbCheck && data.facebook) {
    html += '<div><div class="sec-title">Facebook-допис</div>'
      + '<div class="fb-block">'
      + '<div class="fb-hdr"><div class="fb-logo">f</div><div class="fb-name">Ваша сторінка</div></div>'
      + '<div class="fb-text">' + esc(data.facebook) + '</div>'
      + makeCopyBtn(data.facebook)
      + '</div></div>';
  }
  document.getElementById('output').innerHTML = html;
}
