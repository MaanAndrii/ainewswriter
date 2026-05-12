<?php
/**
 * config.php — налаштування інструкцій для Редактора новин
 * Цей файл читається proxy.php і підставляється як system prompt.
 * Тепер SYSTEM промт береться з prompts.json
 */

require_once __DIR__ . '/core/app_settings.php';

// Завантажуємо SYSTEM промт з JSON
$SYSTEM_PROMPT = get_default_system_prompt();

// Додаткові налаштування (необов'язково)
// $ALLOWED_ORIGINS = get_default_cors_origins(); // обмеження CORS
// $LOG_REQUESTS = false; // логування запитів
?>
