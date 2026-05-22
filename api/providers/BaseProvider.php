<?php
/**
 * BaseProvider — базовий інтерфейс для всіх LLM-провайдерів.
 *
 * Кожен провайдер реалізує три методи:
 *   buildRequest()       — формує URL, заголовки і тіло запиту
 *   processStreamEvent() — обробляє один SSE-евент, повертає delta-текст або null
 *   parseResponse()      — розбирає повну non-streaming відповідь
 *
 * Спільна логіка (normalizeError) живе тут.
 */
abstract class BaseProvider
{
    /**
     * Формує запит до API.
     *
     * @return array{url: string, headers: string[], body: array}
     */
    abstract public function buildRequest(
        string $model,
        string $prompt,
        string $systemPrompt,
        bool   $streamMode
    ): array;

    /**
     * Обробляє один розібраний SSE-евент під час стрімінгу.
     * Оновлює $state за посиланням (usage, web_search_used, та провайдер-специфічні поля).
     *
     * @param  array $ev    Розібраний JSON-об'єкт з рядка "data: ..."
     * @param  array $state Спільний стан стрімінгу (usage, web_search_used, ...)
     * @return string|null  Delta-текст для відправки клієнту, або null (внутрішній евент)
     */
    abstract public function processStreamEvent(array $ev, array &$state): ?string;

    /**
     * Розбирає повну відповідь API (non-streaming).
     *
     * @return array{text: string, usage: array, web_search_used: bool}
     */
    abstract public function parseResponse(array $result): array;

    /**
     * Нормалізує повідомлення про помилку від API.
     * Покриває формати Anthropic, OpenAI-сумісних та Gemini.
     */
    public function normalizeError(array $result, string $rawBody): string
    {
        $msg = (string)(
            $result['error']['message']
            ?? $result['message']
            ?? $result['detail']
            ?? ($result['error'][0]['message'] ?? '')
        );

        if ($msg === '') {
            $msg = trim($rawBody);
        }

        if (function_exists('mb_strlen') && mb_strlen($msg) > 500) {
            $msg = mb_substr($msg, 0, 500) . '…';
        } elseif (strlen($msg) > 500) {
            $msg = substr($msg, 0, 500) . '…';
        }

        return $msg !== '' ? $msg : 'Помилка API';
    }

    /** Початковий стан для стрімінгу — однаковий для всіх провайдерів. */
    public static function initialStreamState(): array
    {
        return [
            'usage' => [
                'input_tokens'                => 0,
                'output_tokens'               => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
            'web_search_used' => false,
        ];
    }
}
