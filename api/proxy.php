<?php
require_once __DIR__ . '/../core/app_settings.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$model = $_POST['model'] ?? '';
$prompt = $_POST['prompt'] ?? '';
$webSearch = !empty($_POST['webSearch']);
$systemPromptOverride = $_POST['systemPromptOverride'] ?? '';

if ($action === 'generate') {
    if (!$prompt || !$model) {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt and model are required']);
        exit;
    }
    $result = call_ai_api($prompt, $model, $webSearch, $systemPromptOverride);
    echo json_encode($result);
    exit;
} elseif (empty($action)) {
    if (!$prompt || !$model) {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt and model are required']);
        exit;
    }
    $result = call_ai_api($prompt, $model, $webSearch, $systemPromptOverride);
    echo json_encode($result);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

function get_api_key_for_model($model) {
    $modelSettings = get_model_settings();
    foreach ($modelSettings as $m) {
        if ($m['id'] === $model) {
            return $m['api_key'] ?? '';
        }
    }
    return '';
}

function get_provider_from_model($model) {
    $modelSettings = get_model_settings();
    foreach ($modelSettings as $m) {
        if ($m['id'] === $model) {
            return $m['provider'] ?? 'anthropic';
        }
    }
    return 'anthropic';
}

function get_model_settings() {
    $settings = load_settings();
    return $settings['models'] ?? [];
}

function call_ai_api($prompt, $model, $webSearch, $systemPromptOverride) {
    $apiKey = get_api_key_for_model($model);
    if (!$apiKey) {
        http_response_code(400);
        echo json_encode(['error' => 'No API key for model: ' . $model]);
        exit;
    }

    $provider = get_provider_from_model($model);
    $response = call_provider_api($provider, $apiKey, $prompt, $model, $webSearch, $systemPromptOverride);

    if (!$response || !isset($response['text'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid response from AI provider']);
        exit;
    }

    return $response;
}

function call_provider_api($provider, $apiKey, $prompt, $model, $webSearch, $systemPromptOverride) {
    switch ($provider) {
        case 'anthropic':
            return call_anthropic($apiKey, $prompt, $model, $webSearch, $systemPromptOverride);
        case 'xai':
            return call_xai($apiKey, $prompt, $model, $webSearch, $systemPromptOverride);
        case 'mistral':
            return call_mistral($apiKey, $prompt, $model, $webSearch, $systemPromptOverride);
        case 'gemini':
            return call_gemini($apiKey, $prompt, $model, $webSearch, $systemPromptOverride);
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported provider: ' . $provider]);
            exit;
    }
}

function call_anthropic($apiKey, $prompt, $model, $webSearch, $systemPromptOverride) {
    $url = 'https://api.anthropic.com/v1/messages';
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ];

    $systemPrompt = $systemPromptOverride ?: get_default_system_prompt();
    $body = [
        'model' => $model,
        'max_tokens' => 4096,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    if ($systemPrompt) {
        array_unshift($body['messages'], ['role' => 'system', 'content' => $systemPrompt]);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Anthropic API error: ' . $httpCode]);
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data['content'][0]['text'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid Anthropic response']);
        exit;
    }

    return [
        'text' => $data['content'][0]['text'],
        'usage' => [
            'input_tokens' => $data['usage']['input_tokens'] ?? 0,
            'output_tokens' => $data['usage']['output_tokens'] ?? 0
        ]
    ];
}

function call_xai($apiKey, $prompt, $model, $webSearch, $systemPromptOverride) {
    $url = 'https://api.x.ai/v1/chat/completions';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    $systemPrompt = $systemPromptOverride ?: get_default_system_prompt();
    $body = [
        'model' => $model,
        'max_tokens' => 4096,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    if ($systemPrompt) {
        array_unshift($body['messages'], ['role' => 'system', 'content' => $systemPrompt]);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['error' => 'xAI API error: ' . $httpCode]);
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid xAI response']);
        exit;
    }

    return [
        'text' => $data['choices'][0]['message']['content'],
        'usage' => [
            'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $data['usage']['completion_tokens'] ?? 0
        ]
    ];
}

function call_mistral($apiKey, $prompt, $model, $webSearch, $systemPromptOverride) {
    $url = 'https://api.mistral.ai/v1/chat/completions';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    $systemPrompt = $systemPromptOverride ?: get_default_system_prompt();
    $body = [
        'model' => $model,
        'max_tokens' => 4096,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    if ($systemPrompt) {
        array_unshift($body['messages'], ['role' => 'system', 'content' => $systemPrompt]);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Mistral API error: ' . $httpCode]);
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid Mistral response']);
        exit;
    }

    return [
        'text' => $data['choices'][0]['message']['content'],
        'usage' => [
            'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $data['usage']['completion_tokens'] ?? 0
        ]
    ];
}

function call_gemini($apiKey, $prompt, $model, $webSearch, $systemPromptOverride) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;
    $headers = ['Content-Type: application/json'];

    $systemPrompt = $systemPromptOverride ?: get_default_system_prompt();
    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 4096
        ]
    ];

    if ($systemPrompt) {
        array_unshift($body['contents'], ['role' => 'system', 'parts' => [['text' => $systemPrompt]]]);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Gemini API error: ' . $httpCode]);
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid Gemini response']);
        exit;
    }

    return [
        'text' => $data['candidates'][0]['content']['parts'][0]['text'],
        'usage' => [
            'input_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0
        ]
    ];
}
?>
