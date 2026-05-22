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

    public function buildRequest(string $model, string $prompt, string $systemPrompt, bool $streamMode, int $maxTokens = 8000): array
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

        if ($streamMode) {
            $body['stream'] = true;
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

    public function processStreamEvent(array $ev, array &$state): ?string
    {
        $type = $ev['type'] ?? '';

        if ($type === 'message_start') {
            $u = $ev['message']['usage'] ?? [];
            $state['usage']['input_tokens']                = (int)($u['input_tokens'] ?? 0);
            $state['usage']['cache_creation_input_tokens'] = (int)($u['cache_creation_input_tokens'] ?? 0);
            $state['usage']['cache_read_input_tokens']     = (int)($u['cache_read_input_tokens'] ?? 0);
            return null;
        }

        if ($type === 'content_block_start') {
            if (($ev['content_block']['type'] ?? '') === 'tool_use') {
                $state['web_search_used'] = true;
            }
            return null;
        }

        if ($type === 'content_block_delta') {
            $delta = ($ev['delta']['type'] ?? '') === 'text_delta' ? ($ev['delta']['text'] ?? '') : '';
            return $delta !== '' ? $delta : null;
        }

        if ($type === 'message_delta') {
            $u = $ev['usage'] ?? [];
            $state['usage']['output_tokens'] = (int)($u['output_tokens'] ?? $state['usage']['output_tokens']);
            return null;
        }

        return null;
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
