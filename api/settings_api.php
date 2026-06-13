<?php
require_once __DIR__ . '/../core/app_settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
apply_cors_headers();

define('API_JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

// ── POST dispatch ─────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $action   = (string)($data['action'] ?? '');
    $dispatch = [
        'save_models'                  => 'handle_save_models',
        'save_key'                     => 'handle_save_key',
        'save_prompt_profiles'         => 'handle_save_prompt_profiles',
        'save_prompt_limits'           => 'handle_save_prompt_limits',
        'save_system_default_override' => 'handle_save_system_default_override',
        'save_all_prompts'             => 'handle_save_all_prompts',
        'save_all_as_default'          => 'handle_save_all_as_default',
        'get_prompt_backup'            => 'handle_get_prompt_backup',
        'delete_prompt_backup'         => 'handle_delete_prompt_backup',
        'save_as_default_prompts'      => 'handle_save_as_default_prompts',
        'restore_default_prompts'      => 'handle_restore_default_prompts',
        'save_password'                => 'handle_save_password',
        'save_prompts'                 => 'handle_save_prompts',
        'get_prompt_backups'           => 'handle_get_prompt_backups',
        'restore_prompt_backup'        => 'handle_restore_prompt_backup',
        'export_settings'              => 'handle_export_settings',
        'import_settings'              => 'handle_import_settings',
        'get_export_counts'            => 'handle_get_export_counts',
        'save_post_processing'         => 'handle_save_post_processing',
        'save_paid_providers'          => 'handle_save_paid_providers',
        'get_api_responses'            => 'handle_get_api_responses',
        'get_logs'                     => 'handle_get_logs',
        'get_history'                  => 'handle_get_history',
        'get_generation'               => 'handle_get_generation',
        'clear_logs'                   => 'handle_clear_logs',
        'clear_history'                => 'handle_clear_history',
    ];

    if (!isset($dispatch[$action])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    $result = ($dispatch[$action])($data);
    $code   = $result['_code'] ?? 200;
    unset($result['_code']);
    http_response_code($code);
    echo json_encode($result, API_JSON_FLAGS);
    exit;
}

// ── GET: повний payload налаштувань ───────────────────────────────────────────

$settings = load_settings();
$models   = $settings['models'] ?? [];
$keys     = get_runtime_keys();

$pp_user = ($settings['prompt_profiles']['user'] ?? []);
echo json_encode([
    'models'                  => $models,
    'paid_providers'          => get_paid_providers(),
    'post_processing'         => get_post_processing(),
    'keys'                    => array_map('mask_val', $keys),
    'default_model'           => $models[0]['id'] ?? null,
    'prompt_system'           => resolve_system_prompt($settings),
    'prompt_default_override' => (string)($settings['system_prompt_default_override'] ?? ''),
    'prompt_profiles'         => $settings['prompt_profiles'] ?? get_default_prompt_profiles(),
    'prompt_profiles_default' => get_default_prompt_profiles(),
    'input_max_chars'         => (int)($pp_user['input_max_chars'] ?? 30000),
    'ai_timeout_sec'          => (int)($pp_user['ai_timeout_sec']  ?? 300),
], API_JSON_FLAGS);

// ── Action handlers ───────────────────────────────────────────────────────────

function handle_save_models(array $d): array {
    $models = $d['models'] ?? null;
    if (!is_array($models)) return _fail(400, 'models must be array');
    $err = validate_models_payload($models);
    if ($err !== null) return _fail(400, $err);
    update_settings(['models' => $models]);
    return ['ok' => true];
}

function handle_save_key(array $d): array {
    $provider = (string)($d['provider'] ?? '');
    $value    = trim((string)($d['value'] ?? ''));
    $map      = get_provider_env_map();
    if (!isset($map[$provider]) || $value === '') return _fail(400, 'Invalid provider or empty value');
    save_env_values([$map[$provider] => $value]);
    return ['ok' => true];
}

function handle_save_prompt_profiles(array $d): array {
    $profiles = $d['profiles'] ?? null;
    if (!is_array($profiles)) return _fail(400, 'profiles must be object');
    $err = validate_prompt_profiles_payload($profiles);
    if ($err !== null) return _fail(400, $err);
    update_settings(['prompt_profiles' => $profiles]);
    return ['ok' => true];
}

function handle_save_prompt_limits(array $d): array {
    $limits = $d['limits'] ?? null;
    if (!is_array($limits)) return _fail(400, 'limits must be object');
    $current  = load_settings();
    $profiles = $current['prompt_profiles'] ?? get_default_prompt_profiles();
    if (!isset($profiles['user']) || !is_array($profiles['user'])) $profiles['user'] = [];
    $profiles['user']['headlines_count']    = max(1,   (int)($limits['headlines_count']    ?? 4));
    $profiles['user']['leads_count']        = max(1,   (int)($limits['leads_count']        ?? 2));
    $profiles['user']['article_max_chars']  = max(300, (int)($limits['article_max_chars']  ?? 3000));
    $profiles['user']['facebook_max_chars'] = max(50,  (int)($limits['facebook_max_chars'] ?? 400));
    $profiles['user']['lead_min_chars']     = max(50,    (int)($limits['lead_min_chars']     ?? 150));
    $profiles['user']['lead_max_chars']     = max(50,    (int)($limits['lead_max_chars']     ?? 180));
    $profiles['user']['input_max_chars']    = max(1000,  (int)($limits['input_max_chars']    ?? 30000));
    $profiles['user']['ai_timeout_sec']     = max(30,    min(600, (int)($limits['ai_timeout_sec'] ?? 300)));
    update_settings(['prompt_profiles' => $profiles]);
    return ['ok' => true];
}

function handle_save_system_default_override(array $d): array {
    $text = trim((string)($d['value'] ?? ''));
    if ($text === '') return _fail(400, 'value must be non-empty');
    update_settings(['system_prompt_default_override' => $text]);
    return ['ok' => true];
}

function handle_save_all_prompts(array $d): array {
    $res = extract_prompt_payload($d);
    if (is_string($res)) return _fail(400, $res);
    update_settings(['system_prompt_default_override' => $res['system'], 'prompt_profiles' => $res['profiles']]);
    return save_prompts_json_response($res);
}

function handle_save_all_as_default(array $d): array {
    $res = extract_prompt_payload($d);
    if (is_string($res)) return _fail(400, $res);
    update_settings(['system_prompt_default_override' => $res['system'], 'prompt_profiles' => $res['profiles']]);
    return save_prompts_json_response($res);
}

function handle_get_prompt_backup(array $d): array {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($d['name'] ?? ''));
    $file = dirname(__DIR__) . '/storage/prompt_backups/' . $name . '.json';
    if (!file_exists($file)) return _fail(404, 'Бекап не знайдено');
    $content = json_decode(file_get_contents($file), true);
    if (!is_array($content)) return _fail(422, 'Файл пошкоджений');
    return ['ok' => true, 'content' => $content];
}

function handle_delete_prompt_backup(array $d): array {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($d['name'] ?? ''));
    $file = dirname(__DIR__) . '/storage/prompt_backups/' . $name . '.json';
    if (!file_exists($file)) return _fail(404, 'Бекап не знайдено');
    @unlink($file);
    return ['ok' => true];
}

function handle_save_as_default_prompts(array $d): array {
    $current      = load_settings();
    $systemPrompt = trim((string)(
        $current['system_prompt_default_override'] !== ''
            ? $current['system_prompt_default_override']
            : ($current['system_prompt_custom'] ?? get_default_system_prompt())
    ));
    $profiles    = $current['prompt_profiles']['user'] ?? get_default_prompt_profiles()['user'] ?? [];
    $newDefaults = [
        'system_prompts'       => ['default' => $systemPrompt],
        'user_prompt_profiles' => ['default' => $profiles],
    ];
    if (!save_prompts_to_json($newDefaults)) return _fail(500, 'Не вдалося записати prompts.json — перевірте права на файл');
    return ['ok' => true];
}

function handle_restore_default_prompts(array $d): array {
    $defaults        = load_prompts_from_json();
    $systemDefault   = trim((string)($defaults['system_prompts']['default'] ?? get_default_system_prompt()));
    $profilesDefault = $defaults['user_prompt_profiles']['default'] ?? get_default_prompt_profiles();
    update_settings(['system_prompt_custom' => '', 'system_prompt_default_override' => $systemDefault, 'prompt_profiles' => ['user' => $profilesDefault]]);
    return ['ok' => true, 'prompt_system' => $systemDefault, 'prompt_profiles' => ['user' => $profilesDefault]];
}

function handle_save_password(array $d): array {
    $target = (string)($d['target'] ?? '');
    $value  = trim((string)($d['value'] ?? ''));
    $map    = ['admin' => 'ADMIN_PASSWORD'];
    if (!isset($map[$target]) || strlen($value) < 8) return _fail(400, 'Invalid target or password too short (min 8)');
    save_env_values([$map[$target] => $value]);
    return ['ok' => true];
}

function handle_save_prompts(array $d): array {
    $prompts = $d['prompts'] ?? null;
    if (!is_array($prompts)) return _fail(400, 'prompts must be object');
    $err = validate_prompts_json_payload($prompts);
    if ($err !== null) return _fail(400, $err);
    if (!save_prompts_to_json($prompts)) return _fail(500, 'Failed to save prompts');
    return ['ok' => true];
}

function handle_get_prompt_backups(array $d): array {
    return ['ok' => true, 'backups' => list_prompt_backups()];
}

function handle_restore_prompt_backup(array $d): array {
    $name = preg_replace('/[^a-z0-9_]/i', '', (string)($d['name'] ?? ''));
    $dir  = dirname(__DIR__) . '/storage/prompt_backups';
    $file = "$dir/$name.json";
    if (!file_exists($file)) return _fail(404, 'Бекап не знайдено');
    $raw    = file_get_contents($file);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) return _fail(422, 'Файл бекапу пошкоджений (невалідний JSON)');
    $err = validate_prompts_json_payload($parsed);
    if ($err !== null) return _fail(422, 'Файл бекапу пошкоджений: ' . $err);
    if (!save_prompts_to_json($parsed)) return _fail(500, 'Не вдалося записати prompts.json');
    $systemPrompt = trim((string)($parsed['system_prompts']['default'] ?? get_default_system_prompt()));
    $profilesUser = $parsed['user_prompt_profiles']['default'] ?? get_default_prompt_profiles()['user'] ?? [];
    update_settings(['system_prompt_custom' => '', 'system_prompt_default_override' => $systemPrompt, 'prompt_profiles' => ['user' => $profilesUser]]);
    return ['ok' => true, 'prompt_system' => $systemPrompt, 'prompt_profiles' => ['user' => $profilesUser]];
}

function handle_export_settings(array $d): array {
    $inclSettings = !isset($d['include_settings']) || (bool)$d['include_settings'];
    $inclKeys     = !isset($d['include_api_keys']) || (bool)$d['include_api_keys'];
    $inclLogs     = isset($d['include_logs'])    && (bool)$d['include_logs'];
    $inclHistory  = isset($d['include_history']) && (bool)$d['include_history'];

    $export = [
        '__version'     => 3,
        '__exported_at' => date('c'),
    ];

    if ($inclSettings) {
        $settings    = load_settings();
        $promptsFile = dirname(__DIR__) . '/prompts.json';
        $promptsData = file_exists($promptsFile) ? json_decode(file_get_contents($promptsFile), true) : [];
        $defaults    = get_default_prompt_profiles();
        $saved       = $settings['prompt_profiles'] ?? [];
        if (!empty($saved['user']) && is_array($saved['user'])) {
            $saved['user'] = array_merge($defaults['user'] ?? [], $saved['user']);
        } else {
            $saved = $defaults;
        }
        $export['models']                         = $settings['models'] ?? [];
        $export['prompt_profiles']                = $saved;
        $export['system_prompt_default_override'] = (string)($settings['system_prompt_default_override'] ?? '');
        $export['prompts_json']                   = $promptsData;
        $export['post_processing']                = get_post_processing();
        $export['paid_providers']                 = get_paid_providers();
    }

    if ($inclKeys) {
        $export['api_keys'] = get_runtime_keys();
    }

    if ($inclLogs || $inclHistory) {
        $db = get_sqlite_db();
        if ($inclLogs && $db) {
            try {
                $export['requests_log'] = $db->query(
                    "SELECT created_at, date, time, model, provider,
                            input_tokens, output_tokens, cache_write, cache_read,
                            cost, duration, prompt_len, web_search, cache_status, error
                     FROM requests ORDER BY id ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $export['requests_log'] = []; }
        }
        if ($inclHistory && $db) {
            try {
                $export['generations_history'] = $db->query(
                    "SELECT created_at, model, provider, source_ref, input_text,
                            extra_instructions, output_json, cost,
                            input_tokens, output_tokens, web_search_used
                     FROM generations ORDER BY id ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $export['generations_history'] = []; }
        }
    }

    return ['ok' => true, 'data' => $export];
}

function handle_get_export_counts(array $d): array {
    $db = get_sqlite_db();
    $logs = 0; $history = 0;
    if ($db) {
        try { $logs    = (int)$db->query("SELECT COUNT(*) FROM requests")->fetchColumn();    } catch (Exception $e) {}
        try { $history = (int)$db->query("SELECT COUNT(*) FROM generations")->fetchColumn(); } catch (Exception $e) {}
    }
    return ['ok' => true, 'logs' => $logs, 'history' => $history];
}

function handle_import_settings(array $d): array {
    $payload = $d['data'] ?? null;
    if (!is_array($payload) || ($payload['__version'] ?? 0) < 1) {
        return _fail(400, 'Невалідний файл імпорту (відсутній __version)');
    }
    if (isset($payload['models'])) {
        if (!is_array($payload['models'])) return _fail(400, 'models: має бути масив');
        $err = validate_models_payload($payload['models']);
        if ($err) return _fail(400, 'models: ' . $err);
    }
    $current     = load_settings();
    $newModels   = isset($payload['models'])           ? $payload['models']          : ($current['models']          ?? []);
    $newProfiles = isset($payload['prompt_profiles'])  ? $payload['prompt_profiles'] : ($current['prompt_profiles'] ?? get_default_prompt_profiles());
    $newOverride = array_key_exists('system_prompt_default_override', $payload)
                    ? (string)$payload['system_prompt_default_override']
                    : (string)($current['system_prompt_default_override'] ?? '');
    update_settings([
        'models'                         => $newModels,
        'system_prompt_default_override' => $newOverride,
        'prompt_profiles'                => $newProfiles,
    ]);
    if (isset($payload['prompts_json'])    && is_array($payload['prompts_json']))    save_prompts_to_json($payload['prompts_json']);
    if (isset($payload['post_processing']) && is_array($payload['post_processing'])) save_post_processing($payload['post_processing']);
    if (isset($payload['paid_providers'])  && is_array($payload['paid_providers']))  save_paid_providers($payload['paid_providers']);
    $importedKeys = 0;
    if (isset($payload['api_keys']) && is_array($payload['api_keys'])) {
        $toSave = [];
        foreach (get_provider_env_map() as $provider => $envKey) {
            $val = trim((string)($payload['api_keys'][$provider] ?? ''));
            if ($val !== '') $toSave[$envKey] = $val;
        }
        if ($toSave) { save_env_values($toSave); $importedKeys = count($toSave); }
    }
    $logsImported    = 0;
    $historyImported = 0;
    $db = get_sqlite_db();
    if ($db) {
        if (isset($payload['requests_log']) && is_array($payload['requests_log'])) {
            try {
                $stmt = $db->prepare(
                    "INSERT OR IGNORE INTO requests
                     (created_at, date, time, model, provider, input_tokens, output_tokens,
                      cache_write, cache_read, cost, duration, prompt_len, web_search, cache_status, error)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                foreach ($payload['requests_log'] as $row) {
                    if (!is_array($row)) continue;
                    $stmt->execute([
                        $row['created_at'] ?? '', $row['date'] ?? '', $row['time'] ?? '',
                        $row['model'] ?? '', $row['provider'] ?? '',
                        (int)($row['input_tokens'] ?? 0), (int)($row['output_tokens'] ?? 0),
                        (int)($row['cache_write'] ?? 0), (int)($row['cache_read'] ?? 0),
                        (float)($row['cost'] ?? 0), (float)($row['duration'] ?? 0),
                        (int)($row['prompt_len'] ?? 0), (int)($row['web_search'] ?? 0),
                        $row['cache_status'] ?? 'no-cache', $row['error'] ?? null,
                    ]);
                    $logsImported += $stmt->rowCount();
                }
            } catch (Exception $e) {}
        }
        if (isset($payload['generations_history']) && is_array($payload['generations_history'])) {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO generations
                     (created_at, model, provider, source_ref, input_text,
                      extra_instructions, output_json, cost, input_tokens, output_tokens, web_search_used)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                );
                foreach ($payload['generations_history'] as $row) {
                    if (!is_array($row)) continue;
                    $stmt->execute([
                        $row['created_at'] ?? '', $row['model'] ?? '', $row['provider'] ?? '',
                        $row['source_ref'] ?? '', $row['input_text'] ?? '',
                        $row['extra_instructions'] ?? '', $row['output_json'] ?? '',
                        (float)($row['cost'] ?? 0), (int)($row['input_tokens'] ?? 0),
                        (int)($row['output_tokens'] ?? 0), (int)($row['web_search_used'] ?? 0),
                    ]);
                    $historyImported++;
                }
            } catch (Exception $e) {}
        }
    }
    return ['ok' => true, 'imported' => [
        'models_count'        => count($newModels),
        'has_prompts_json'    => isset($payload['prompts_json']),
        'has_profiles'        => isset($payload['prompt_profiles']),
        'has_system'          => array_key_exists('system_prompt_default_override', $payload),
        'keys_imported'       => $importedKeys,
        'has_post_processing' => isset($payload['post_processing']),
        'has_paid_providers'  => isset($payload['paid_providers']),
        'logs_imported'       => $logsImported,
        'history_imported'    => $historyImported,
    ]];
}

function handle_clear_logs(array $d): array {
    $db = get_sqlite_db();
    if (!$db) return _fail(503, 'SQLite недоступний');
    try {
        $db->exec('DELETE FROM requests');
        return ['ok' => true, 'deleted' => true];
    } catch (Exception $e) {
        return _fail(500, $e->getMessage());
    }
}

function handle_clear_history(array $d): array {
    $db = get_sqlite_db();
    if (!$db) return _fail(503, 'SQLite недоступний');
    try {
        $db->exec('DELETE FROM generations');
        return ['ok' => true, 'deleted' => true];
    } catch (Exception $e) {
        return _fail(500, $e->getMessage());
    }
}

function handle_save_post_processing(array $d): array {
    $pp = $d['post_processing'] ?? null;
    if (!is_array($pp)) return _fail(400, 'post_processing must be object');
    save_post_processing($pp);
    return ['ok' => true];
}

function handle_save_paid_providers(array $d): array {
    $providers = $d['providers'] ?? [];
    if (!is_array($providers)) return _fail(400, 'providers must be array');
    save_paid_providers($providers);
    return ['ok' => true];
}

function handle_get_api_responses(array $d): array {
    $file = dirname(__DIR__) . '/storage/api_responses.json';
    $list = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        if ($raw) $list = json_decode($raw, true) ?: [];
    }
    return ['ok' => true, 'responses' => $list];
}

function handle_get_logs(array $d): array {
    $filterDate = isset($d['date']) && $d['date'] !== '' ? (string)$d['date'] : '';
    $rows       = [];
    $summary    = ['cnt' => 0, 'total_cost' => 0.0, 'total_inp' => 0, 'total_out' => 0, 'total_cache_r' => 0];
    $db = get_sqlite_db();
    if ($db) {
        try {
            $where  = $filterDate !== '' ? ' WHERE date = ?' : '';
            $params = $filterDate !== '' ? [$filterDate] : [];
            $stmt   = $db->prepare(
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
            $s       = $sum->fetch(PDO::FETCH_ASSOC);
            $summary = [
                'cnt'           => (int)($s['cnt']  ?? 0),
                'total_cost'    => (float)($s['tc']  ?? 0),
                'total_inp'     => (int)($s['ti']  ?? 0),
                'total_out'     => (int)($s['to2'] ?? 0),
                'total_cache_r' => (int)($s['tr2'] ?? 0),
            ];
        } catch (Exception $e) { /* SQLite error — return empty */ }
    }
    return ['ok' => true, 'rows' => $rows, 'summary' => $summary];
}

function handle_get_history(array $d): array {
    $db = get_sqlite_db();
    if (!$db) return _fail(503, 'SQLite недоступний');
    $page   = max(1, (int)($d['page'] ?? 1));
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
        return ['ok' => true, 'items' => $rows, 'total' => $total, 'page' => $page];
    } catch (Exception $e) {
        return _fail(500, $e->getMessage());
    }
}

function handle_get_generation(array $d): array {
    $db = get_sqlite_db();
    if (!$db) return _fail(503, 'SQLite недоступний');
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) return _fail(400, 'Invalid id');
    try {
        $stmt = $db->prepare("SELECT * FROM generations WHERE id = ?");
        $stmt->execute([$id]);
        return ['ok' => true, 'item' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
    } catch (Exception $e) {
        return _fail(500, $e->getMessage());
    }
}

// ── Утиліти файлу ─────────────────────────────────────────────────────────────

function _fail(int $code, string $error): array {
    return ['_code' => $code, 'ok' => false, 'error' => $error];
}

function extract_prompt_payload(array $data): array|string {
    $systemText = trim((string)($data['system'] ?? ''));
    $profiles   = $data['profiles'] ?? null;
    if ($systemText === '') return 'system must be non-empty';
    if (!is_array($profiles)) return 'profiles must be object';
    $err = validate_prompt_profiles_payload($profiles);
    if ($err !== null) return $err;
    return ['system' => $systemText, 'profiles' => $profiles];
}

function save_prompts_json_response(array $res): array {
    $newDefaults = [
        'system_prompts'       => ['default' => $res['system']],
        'user_prompt_profiles' => ['default' => $res['profiles']['user'] ?? []],
    ];
    if (!save_prompts_to_json($newDefaults)) return _fail(500, 'Не вдалося записати prompts.json — перевірте права на файл');
    return ['ok' => true];
}

function save_prompts_to_json($prompts): bool {
    $promptsFile = dirname(__DIR__) . '/prompts.json';
    $json = json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    backup_prompts_file($promptsFile);
    return file_put_contents($promptsFile, $json, LOCK_EX) !== false;
}

function backup_prompts_file(string $promptsFile): void {
    if (!file_exists($promptsFile)) return;
    $dir = dirname(__DIR__) . '/storage/prompt_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ts  = date('Ymd_His');
    @copy($promptsFile, "$dir/prompts_$ts.json");
    $files = glob("$dir/prompts_*.json") ?: [];
    if (count($files) > 5) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - 5) as $old) @unlink($old);
    }
}

function list_prompt_backups(): array {
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

function validate_prompt_profiles_payload($profiles): ?string {
    if (!is_array($profiles)) return 'profiles must be object';
    $user = $profiles['user'] ?? null;
    if (!is_array($user)) return 'profiles.user must be object';
    foreach (['json_rule', 'requirements_title', 'input_title', 'news_fields_on', 'news_requirements_on', 'tone_prefix', 'depth_prefix', 'source_ref_rule'] as $field) {
        $v = $user[$field] ?? '';
        if (!is_string($v) || trim($v) === '') return "profiles.user.$field is required and must be non-empty";
    }
    $tsr = $user['tone_short_rules'] ?? null;
    if (!is_array($tsr)) return 'profiles.user.tone_short_rules must be object';
    foreach (['neutral', 'intriguing', 'emotional', 'seo'] as $k) {
        if (!isset($tsr[$k]) || trim((string)$tsr[$k]) === '') return "profiles.user.tone_short_rules.$k is required";
    }
    return null;
}

function validate_prompts_json_payload($prompts): ?string {
    if (!is_array($prompts)) return 'prompts must be object';
    $sp = $prompts['system_prompts'] ?? null;
    if (!is_array($sp)) return 'prompts.system_prompts must be object';
    if (!is_string($sp['default'] ?? null) || trim($sp['default']) === '') return 'prompts.system_prompts.default must be non-empty string';
    $up = $prompts['user_prompt_profiles'] ?? null;
    if (!is_array($up)) return 'prompts.user_prompt_profiles must be object';
    if (!is_array($up['default'] ?? null)) return 'prompts.user_prompt_profiles.default must be object';
    return null;
}

function validate_models_payload($models): ?string {
    $seenIds = [];
    foreach ($models as $idx => $m) {
        if (!is_array($m)) return 'model[' . $idx . '] must be object';
        $id       = trim((string)($m['id']       ?? ''));
        $label    = trim((string)($m['label']    ?? ''));
        $provider = trim((string)($m['provider'] ?? ''));
        $inp      = $m['inp'] ?? null;
        $out      = $m['out'] ?? null;
        if ($id === '')                                              return 'model[' . $idx . '].id is required';
        if ($label === '')                                           return 'model[' . $idx . '].label is required';
        if (!in_array($provider, PROVIDERS_ALL, true))              return 'model[' . $idx . '].provider invalid';
        if (!is_numeric($inp) || (float)$inp < 0)                   return 'model[' . $idx . '].inp must be >= 0';
        if (!is_numeric($out) || (float)$out < 0)                   return 'model[' . $idx . '].out must be >= 0';
        if (isset($seenIds[$id]))                                    return 'duplicate model id: ' . $id;
        $seenIds[$id] = true;
    }
    return null;
}
