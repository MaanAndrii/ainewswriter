<?php
define('MAX_SETTINGS_FILE_SIZE', 100000); // 100KB
define('SETTINGS_FILE', __DIR__ . '/../storage/settings_store.php');

function get_default_settings() {
    return [
        'models' => get_default_models(),
        'system_prompt_custom' => '',
        'system_prompt_default_override' => '',
        'prompt_profiles' => get_default_prompt_profiles(),
    ];
}

function get_default_models() {
    return [
        [
            'id' => 'claude-haiku-4-5-20251001',
            'label' => 'Claude 4.5 Haiku',
            'provider' => 'anthropic',
            'inp' => 0.8,
            'out' => 4.0,
            'web_search' => false,
        ],
        [
            'id' => 'claude-3-5-sonnet-20241022',
            'label' => 'Claude 3.5 Sonnet',
            'provider' => 'anthropic',
            'inp' => 3.0,
            'out' => 15.0,
            'web_search' => false,
        ],
        [
            'id' => 'claude-3-5-sonnet-20240620',
            'label' => 'Claude 3.5 Sonnet (легасі)',
            'provider' => 'anthropic',
            'inp' => 3.0,
            'out' => 15.0,
            'web_search' => false,
        ],
        [
            'id' => 'gpt-4o-mini-2024-07-18',
            'label' => 'GPT-4o Mini',
            'provider' => 'xai',
            'inp' => 1.5,
            'out' => 6.0,
            'web_search' => false,
        ],
        [
            'id' => 'gpt-4o-2024-05-13',
            'label' => 'GPT-4o',
            'provider' => 'xai',
            'inp' => 5.0,
            'out' => 15.0,
            'web_search' => false,
        ],
        [
            'id' => 'gemini-2.0-flash',
            'label' => 'Gemini 2.0 Flash',
            'provider' => 'gemini',
            'inp' => 0.5,
            'out' => 2.0,
            'web_search' => false,
        ],
        [
            'id' => 'mistral-large-2407',
            'label' => 'Mistral Large 2',
            'provider' => 'mistral',
            'inp' => 2.0,
            'out' => 6.0,
            'web_search' => false,
        ],
    ];
}

function get_default_prompt_profiles() {
    $prompts = load_prompts_from_json();
    $defaultProfile = $prompts['user_prompt_profiles']['default'] ?? [];
    return ['user' => $defaultProfile];
}

function load_prompts_from_json() {
    $file = __DIR__ . '/../prompts.json';
    if (!file_exists($file) || !is_readable($file)) {
        return ['user_prompt_profiles' => ['default' => []]];
    }
    $content = file_get_contents($file);
    $prompts = json_decode($content, true);
    if (!is_array($prompts)) {
        return ['user_prompt_profiles' => ['default' => []]];
    }
    return $prompts;
}

function load_settings() {
    if (!file_exists(SETTINGS_FILE) || !is_readable(SETTINGS_FILE)) {
        return get_default_settings();
    }
    $size = @filesize(SETTINGS_FILE);
    if (is_int($size) && $size > MAX_SETTINGS_FILE_SIZE) {
        return get_default_settings();
    }
    try {
        $settings = @include SETTINGS_FILE;
        if (!is_array($settings)) {
            return get_default_settings();
        }
        return normalize_settings($settings);
    } catch (Throwable $e) {
        error_log("Error loading settings: " . $e->getMessage());
        return get_default_settings();
    }
}

function normalize_settings($settings) {
    $defaults = get_default_settings();
    if (!is_array($settings)) {
        return $defaults;
    }

    $models = $settings['models'] ?? [];
    if (!is_array($models)) {
        $models = $defaults['models'];
    }

    $normModels = [];
    foreach ($models as $m) {
        if (empty($m['id']) || empty($m['provider'])) {
            continue;
        }
        $normModels[] = [
            'id' => trim((string)$m['id']),
            'label' => trim((string)($m['label'] ?? $m['id'])),
            'provider' => in_array(($m['provider'] ?? ''), ['anthropic', 'xai', 'gemini', 'mistral'], true) ? $m['provider'] : 'anthropic',
            'inp' => (float)($m['inp'] ?? 3.0),
            'out' => (float)($m['out'] ?? 15.0),
            'web_search' => !empty($m['web_search']),
        ];
    }
    if ($models && !$normModels) {
        $normModels = $defaults['models'];
    }

    $profiles = $settings['prompt_profiles'] ?? get_default_prompt_profiles();

    if (is_array($profiles) && !isset($profiles['user'])) {
        $profiles = ['user' => $profiles];
    }

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

function get_runtime_keys() {
    return [
        'openai' => getenv('OPENAI_API_KEY') ?: '',
        'anthropic' => getenv('ANTHROPIC_API_KEY') ?: '',
        'xai' => getenv('XAI_API_KEY') ?: '',
        'gemini' => getenv('GEMINI_API_KEY') ?: '',
        'mistral' => getenv('MISTRAL_API_KEY') ?: '',
    ];
}

function get_default_system_prompt() {
    $prompts = load_prompts_from_json();
    return $prompts['system_prompts']['default'] ?? 'Ти — досвідчений редактор українського онлайн-медіа. Твоє завдання — переробляти вхідний матеріал у якісні новинні тексти з урахуванням вимог до стилю, структури та обсягу.';
}

function resolve_system_prompt($settings) {
    return trim((string)($settings['system_prompt_default_override'] ?? $settings['system_prompt_custom'] ?? get_default_system_prompt()));
}

function get_prompt_profile_user() {
    $settings = load_settings();
    return $settings['prompt_profiles']['user'] ?? get_default_prompt_profiles()['user'];
}

function save_prompt_profile_user($fields) {
    $settings = load_settings();
    $profiles = $settings['prompt_profiles'] ?? get_default_prompt_profiles();
    if (!isset($profiles['user'])) {
        $profiles['user'] = [];
    }
    foreach ($fields as $key => $value) {
        $profiles['user'][$key] = $value;
    }
    save_settings([
        'models' => $settings['models'] ?? [],
        'system_prompt_custom' => $settings['system_prompt_custom'] ?? '',
        'system_prompt_default_override' => $settings['system_prompt_default_override'] ?? '',
        'prompt_profiles' => $profiles,
    ]);
}
?>
