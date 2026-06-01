<?php
require_once __DIR__ . '/BaseProvider.php';

class AnthropicProvider extends BaseProvider
{
    public function __construct(
        private string $key,
        private bool   $useWebSearch
    ) {
        if ($key === '') {
            throw new RuntimeException('Anthropic API-ключ не задано. Додайте ANTHROPIC_API_KEY у env');
        }
    }

    public function buildRequest(string $model, string $prompt, string $systemPrompt, int $maxTokens = 8000): array
    {
        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($systemPrompt !== '') {
            $body['system'] = [[
                'type'          => 'text',
                'text'          => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        if ($this->useWebSearch) {
            $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search']];
        }

        return [
            'url'     => 'https://api.anthropic.com/v1/messages',
            'headers' => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->key,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: prompt-caching-2024-07-31',
            ],
            'body' => $body,
        ];
    }

    public function parseResponse(array $result): array
    {
        $text          = '';
        $webSearchUsed = false;

        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'web_search') {
                $webSearchUsed = true;
            }
            if (($block['type'] ?? '') === 'tool_result') {
                $webSearchUsed = true;
            }
        }

        return [
            'text'            => $text,
            'usage'           => $result['usage'] ?? [],
            'web_search_used' => $webSearchUsed,
        ];
    }
}
