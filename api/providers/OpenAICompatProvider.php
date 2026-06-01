<?php
require_once __DIR__ . '/BaseProvider.php';

/**
 * OpenAICompatProvider — єдиний клас для всіх OpenAI-сумісних провайдерів:
 * xAI (Grok), Mistral, OpenAI, DeepSeek, Groq.
 *
 * Щоб додати новий провайдер — рядок у URLS і ENV_NAMES.
 */
class OpenAICompatProvider extends BaseProvider
{
    private const URLS = [
        'xai'      => 'https://api.x.ai/v1/chat/completions',
        'mistral'  => 'https://api.mistral.ai/v1/chat/completions',
        'openai'   => 'https://api.openai.com/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
        'groq'     => 'https://api.groq.com/openai/v1/chat/completions',
    ];

    private const ENV_NAMES = [
        'xai'      => 'XAI_API_KEY',
        'mistral'  => 'MISTRAL_API_KEY',
        'openai'   => 'OPENAI_API_KEY',
        'deepseek' => 'DEEPSEEK_API_KEY',
        'groq'     => 'GROQ_API_KEY',
    ];

    public function __construct(
        private string $provider,
        private string $key
    ) {
        if ($key === '') {
            $envName = self::ENV_NAMES[$provider] ?? strtoupper($provider) . '_API_KEY';
            throw new RuntimeException(
                ucfirst($provider) . ' API-ключ не задано. Додайте ' . $envName . ' у env'
            );
        }
    }

    public function buildRequest(string $model, string $prompt, string $systemPrompt, int $maxTokens = 8000): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return [
            'url'     => self::URLS[$this->provider] ?? '',
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->key,
            ],
            'body' => [
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => $maxTokens,
                'temperature' => 0.4,
            ],
        ];
    }

    public function parseResponse(array $result): array
    {
        $content = $result['choices'][0]['message']['content'] ?? '';
        $text    = is_array($content)
            ? implode('', array_column($content, 'text'))
            : (string)$content;

        // DeepSeek-R1 поміщає «міркування» в <think>...</think> перед відповіддю
        if ($this->provider === 'deepseek') {
            $text = (string)preg_replace('/<think>.*?<\/think>/s', '', $text);
        }

        $u = $result['usage'] ?? [];

        return [
            'text'            => $text,
            'usage'           => [
                'input_tokens'                => (int)($u['prompt_tokens']     ?? 0),
                'output_tokens'               => (int)($u['completion_tokens'] ?? 0),
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
            'web_search_used' => false,
        ];
    }
}
