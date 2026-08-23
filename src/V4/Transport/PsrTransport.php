<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Transport;

use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Транспорт поверх PSR-18 клиента (Guzzle, Symfony HttpClient и т.д.).
 *
 * Позволяет встроить VK API в инфраструктуру проекта:
 * общий пул соединений, прокси, middleware, метрики.
 */
final class PsrTransport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl = 'https://api.vk.com/method/',
        private readonly float $timeout = 30.0,
    ) {}

    public function call(string $method, array $params = []): array
    {
        $request = $this->requestFactory
            ->createRequest('POST', $this->baseUrl . $method)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(\http_build_query($params)));

        if ($this->timeout > 0 && \method_exists($request, 'withTimeout')) {
            $request = $request->withTimeout($this->timeout);
        }

        try {
            $response = $this->client->sendRequest($request);
            $body = (string) $response->getBody();
        } catch (ClientExceptionInterface $e) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "PSR-18 транспорт: сбой сети при вызове VK API ({$method}): {$e->getMessage()}",
                [],
                $e,
            );
        }

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
