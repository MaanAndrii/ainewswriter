<?php
require_once __DIR__ . '/BaseProvider.php';

class GeminiProvider extends BaseProvider
{
    public function __construct(
        private string $key,
        private bool   $useWebSearch
    ) {
        if ($key === '') {
            throw new RuntimeException('Gemini API-ключ не задано. Додайте GEMINI_API_KEY у env');
        }
    }

    public function buildRequest(string $model, string $prompt, string $systemPrompt, int $maxTokens = 8000): array
    {
        $text = ($systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . $prompt;

        $body = [
            'contents'        => [['parts' => [['text' => $text]]]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => $maxTokens],
        ];

        if ($this->useWebSearch) {
            $body['tools'] = [['google_search' => (object)[]]];
        }

        return [
            'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/'
                       . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->key),
            'headers' => ['Content-Type: application/json'],
            'body'    => $body,
        ];
    }

    public function parseResponse(array $result): array
    {
        // API-level error returned with HTTP 200 (Gemini billing/quota quirk)
        if (isset($result['error'])) {
            return ['text' => '', 'error' => (string)($result['error']['message'] ?? 'Gemini API error'), 'usage' => [], 'web_search_used' => false];
        }

        // Collect text from all parts (not just parts[0])
        $parts = $result['candidates'][0]['content']['parts'] ?? [];
        $textParts = array_filter($parts, static fn($p) => isset($p['text']));
        $text = implode('', array_column($textParts, 'text'));

        // Safety / policy block — finishReason is set but content is empty
        if ($text === '') {
            $reason = $result['candidates'][0]['finishReason'] ?? '';
            if ($reason !== '' && $reason !== 'STOP') {
                return ['text' => '', 'error' => 'Gemini відхилив запит (' . $reason . ')', 'usage' => [], 'web_search_used' => false];
            }
        }

        $webSearchUsed = !empty($result['candidates'][0]['groundingMetadata']);

        return [
            'text'            => $text,
            'usage'           => [
                'input_tokens'                => (int)($result['usageMetadata']['promptTokenCount']     ?? 0),
                'output_tokens'               => (int)($result['usageMetadata']['candidatesTokenCount'] ?? 0),
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
            'web_search_used' => $webSearchUsed,
        ];
    }
}
