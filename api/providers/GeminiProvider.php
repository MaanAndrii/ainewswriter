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

    public function buildRequest(string $model, string $prompt, string $systemPrompt, bool $streamMode): array
    {
        $text = ($systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . $prompt;

        $body = [
            'contents'        => [['parts' => [['text' => $text]]]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 4000],
        ];

        if ($this->useWebSearch) {
            $body['tools'] = [['google_search' => (object)[]]];
        }

        $endpoint = $streamMode
            ? ':streamGenerateContent?alt=sse&key='
            : ':generateContent?key=';

        return [
            'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/'
                       . rawurlencode($model) . $endpoint . rawurlencode($this->key),
            'headers' => ['Content-Type: application/json'],
            'body'    => $body,
        ];
    }

    public function processStreamEvent(array $ev, array &$state): ?string
    {
        // Gemini streaming повертає повний накопичений текст у кожному чанку.
        // Відстежуємо байтовий офсет і відправляємо лише новий приріст.
        $fullText = $ev['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $delta    = null;

        if ($fullText !== '') {
            $prevLen = $state['gemini_prev_len'] ?? 0;
            $new     = substr($fullText, $prevLen);
            if ($new !== '') {
                $state['gemini_prev_len'] = $prevLen + strlen($new);
                $delta = $new;
            }
        }

        if (!empty($ev['candidates'][0]['groundingMetadata'])) {
            $state['web_search_used'] = true;
        }

        if (isset($ev['usageMetadata'])) {
            $state['usage']['input_tokens']  = (int)($ev['usageMetadata']['promptTokenCount'] ?? 0);
            $state['usage']['output_tokens'] = (int)($ev['usageMetadata']['candidatesTokenCount'] ?? 0);
        }

        return $delta;
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
