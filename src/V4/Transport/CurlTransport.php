<?php

namespace DigitalStars\SimpleVK\V4\Transport;

use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;

/**
 * Транспорт по умолчанию на ext-curl.
 *
 * POST form-urlencoded, ожидает JSON-ответ VK API.
 * Переиспользует один curl-хендлер — совместимо с долгоживущими процессами.
 */
final class CurlTransport implements Transport
{
    private const DEFAULT_TIMEOUT = 30.0;

    public function __construct(
        private readonly string $baseUrl = 'https://api.vk.com/method/',
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    public function call(string $method, array $params = []): array
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(
                'Транспорт CurlTransport требует расширение ext-curl. Установите PSR-18 транспорт через ClientConfig::withTransport().',
            );
        }

        $ch = \curl_init();
        \curl_setopt_array($ch, [
            \CURLOPT_URL => \rtrim($this->baseUrl, '/') . '/' . $method,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => \http_build_query($params),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = \curl_error($ch);

        if ($body === false) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Сбой сети при вызове VK API ({$method}): [{$errno}] {$error}",
            );
        }

        return self::decode((string) $body, $method);
    }

    /**
     * Декодирование ответа вынесено публично для юнит-тестов.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function decode(string $body, string $method): array
    {
        try {
            $decoded = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "VK API вернул некорректный JSON для {$method}: {$e->getMessage()}",
            );
        }

        if (!\is_array($decoded)) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "VK API вернул не-JSON-объект для {$method}",
            );
        }

        return $decoded;
    }
}
