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
        $text          = (string)($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
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
