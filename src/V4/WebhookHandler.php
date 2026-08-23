<?php

namespace DigitalStars\SimpleVK\V4;

use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Message\IncomingMessage;
use SensitiveParameter;

/**
 * Обработка Callback API (вебхуков): подтверждение, секрет, диспетчеризация.
 *
 * Библиотека не поднимает HTTP-сервер: метод принимает уже полученное тело
 * запроса и возвращает строку ответа для VK.
 */
final class WebhookHandler
{
    public function __construct(
        private readonly Bot $bot,
    ) {}

    /**
     * Обрабатывает тело POST-запроса Callback API.
     *
     * @param string $body Сырое тело запроса.
     * @param string|null $secret Заголовок X-Retry-Secret / секрет из конфигурации.
     *
     * @return string Строка для отправки в ответ VK ('ok' или confirmation).
     */
    public function handle(string $body, #[SensitiveParameter] ?string $secret = null): string
    {
        $config = $this->bot->config;

        try {
            $data = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Callback API: некорректный JSON: {$e->getMessage()}",
            );
        }

        if (!\is_array($data)) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'Callback API: ожидался JSON-объект');
        }

        $type = (string) ($data['type'] ?? '');

        if ($type === 'confirmation') {
            return (string) ($config->confirmationCode ?? 'ok');
        }

        // Секрет проверяем только на событиях: VK не подписывает confirmation
        $expectedSecret = $data['secret'] ?? $secret;
        if (
            $config->confirmationSecret !== null
            && !\hash_equals($config->confirmationSecret, (string) ($expectedSecret ?? ''))
        ) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'Callback API: неверный secret');
        }

        // Групповое событие — передаём дальше в стандартный пайплайн.
        $this->bot->dispatch($data);

        return 'ok';
    }
}
