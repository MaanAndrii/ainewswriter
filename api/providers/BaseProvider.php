<?php
/**
 * BaseProvider — базовий інтерфейс для всіх LLM-провайдерів.
 *
 * Кожен провайдер реалізує два методи:
 *   buildRequest()  — формує URL, заголовки і тіло запиту (non-streaming)
 *   parseResponse() — розбирає повну відповідь
 *
 * Спільна логіка (normalizeError) живе тут.
 */
abstract class BaseProvider
{
    /**
     * Формує non-streaming запит до API.
     *
     * @return array{url: string, headers: string[], body: array}
     */
    abstract public function buildRequest(
        string $model,
        string $prompt,
        string $systemPrompt,
        int    $maxTokens = 8000
    ): array;

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
}
