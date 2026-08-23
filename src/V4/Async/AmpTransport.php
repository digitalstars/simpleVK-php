<?php

namespace DigitalStars\SimpleVK\V4\Async;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Transport\AsyncTransport;
use DigitalStars\SimpleVK\V4\Transport\Transport;

use function Amp\async;

/**
 * AsyncTransport поверх amphp/http-client.
 */
final class AmpTransport implements Transport, AsyncTransport
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $baseUrl = 'https://api.vk.com/method/',
    ) {}

    public function call(string $method, array $params = []): array
    {
        return $this->callAsync($method, $params)->await();
    }

    public function callAsync(string $method, array $params = []): Future
    {
        $request = new Request(\rtrim($this->baseUrl, '/') . '/' . $method, 'POST');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->setBody(\http_build_query($params));

        return async(function () use ($method, $request): array {
            $response = $this->client->request($request);
            $body = $response->getBody()->buffer();

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
        });
    }
}
