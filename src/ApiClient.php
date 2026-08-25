<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

use Amp\Future;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Exception\SimpleVkException;
use DigitalStars\SimpleVK\Transport\AsyncTransport;
use DigitalStars\SimpleVK\Transport\Transport;
use Psr\Log\LoggerInterface;

/**
 * Исполнитель вызовов VK API: авторизация, версии, ретраи, rate limit, логирование.
 *
 * Единственная точка выхода библиотеки в сеть — через Transport из конфига,
 * поэтому в тестах подменяется на FakeTransport без сети.
 */
final class ApiClient
{
    /** Коды VK API, при которых запрос стоит повторить. */
    private const array RETRYABLE_ERRORS = [1, 6, 9, 10];

    private Transport $transport;
    private LoggerInterface $logger;

    /** @var float Монотонная отметка последнего запроса для rate limit. */
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly ClientConfig $config,
        ?Transport $transport = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->transport = $transport ?? $config->getTransport();
        $this->logger = $logger ?? $config->logger;
    }

    /**
     * Синхронный вызов метода VK API.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> Содержимое 'response'.
     */
    public function call(string $method, array $params = []): array
    {
        return $this->execute($method, $params);
    }

    /**
     * Итерация по всем элементам метода с пагинацией VK (offset или start_from).
     *
     * Пример:
     *   foreach ($api->iterate('groups.getMembers', ['group_id' => 1], 'items') as $member) { ... }
     *
     * @param array<string, mixed> $params Начальные параметры; offset/next_from подставляются автоматически.
     * @param non-empty-string $itemsKey Ключ массива элементов внутри response.
     * @param positive-int $pageSize Страничный лимит (count), если в params не задан.
     *
     * @return \Generator<int, mixed>
     */
    public function iterate(
        string $method,
        array $params = [],
        string $itemsKey = 'items',
        int $pageSize = 1000,
    ): \Generator {
        $params['count'] ??= $pageSize;
        $params['offset'] ??= 0;

        do {
            $response = $this->call($method, $params);

            // Формат A: response = [items, count] либо response.items + offset
            // Формат B: response.items + next_from (cursor-пагинация)
            $items = $response[$itemsKey] ?? $response['items'] ?? [];
            if (!\is_array($items)) {
                return;
            }

            yield from \array_values($items);

            $received = \count($items);

            if (isset($response['next_from'])) {
                // cursor-пагинация (newsfeed.execute-style)
                $params['start_from'] = (string) $response['next_from'];
                unset($params['offset']);
            } else {
                if ($received === 0 || $received < (int) $params['count']) {
                    return;
                }
                $params['offset'] = (int) $params['offset'] + $received;
            }
        } while (true);
    }

    /**
     * Асинхронный вызов метода VK API (транспорт должен реализовать AsyncTransport).
     *
     * @param array<string, mixed> $params
     *
     * @return Future<array<string, mixed>>
     */
    public function callAsync(string $method, array $params = []): Future
    {
        if (!$this->transport instanceof AsyncTransport) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, \sprintf(
                'Транспорт %s не поддерживает асинхронные вызовы. Подключите AsyncTransport (например AmpAdapter::transport()).',
                $this->transport::class,
            ));
        }

        $prepared = $this->prepareParams($params);

        return $this->transport->callAsync($method, $prepared)->map(
            fn(array $envelope): array => $this->unwrap($method, $envelope),
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function execute(string $method, array $params): array
    {
        $prepared = $this->prepareParams($params);
        $attempts = $this->config->retryMaxAttempts;
        $backoffMs = $this->config->retryBackoffMs;

        for ($attempt = 1;; ++$attempt) {
            $this->throttle();

            try {
                return $this->unwrap($method, $this->transport->call($method, $prepared));
            } catch (SimpleVkException $e) {
                $retryable = $e->vkErrorCode >= 0 && \in_array($e->vkErrorCode, self::RETRYABLE_ERRORS, true);

                if (!$retryable || $attempt >= $attempts) {
                    throw $e;
                }

                $this->logger->warning('VK API retry', [
                    'method' => $method,
                    'attempt' => $attempt,
                    'vk_error' => $e->vkErrorCode,
                    'backoff_ms' => $backoffMs,
                ]);
                \usleep(\max(0, $backoffMs * 1000));
                $backoffMs *= 2; // экспоненциальная задержка
            }
        }

        // Недостижимо: цикл завершается только через throw или return.
        throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, "VK API: исчерпаны попытки вызова {$method}");
    }

    /**
     * Добавляет служебные параметры и нормализует значения для HTTP-транспорта.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function prepareParams(array $params): array
    {
        $params['access_token'] ??= $this->config->token;
        $params['v'] ??= $this->config->apiVersion;

        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                // Вложенные структуры VK API ожидает в JSON (keyboard, forward и т.п.)
                $params[$key] = \json_encode(
                    $value,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                );
            }
        }

        return $params;
    }

    /**
     * Извлекает 'response' из конверта или бросает исключение по 'error'.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>
     */
    private function unwrap(string $method, array $envelope): array
    {
        if (\array_key_exists('error', $envelope)) {
            $error = $envelope['error'];
            $code = \is_array($error) ? (int) ($error['error_code'] ?? 0) : 0;
            $message = \is_array($error)
                ? "VK API Error {$code} в {$method}: " . ($error['error_msg'] ?? 'unknown')
                : "VK API Error в {$method}: некорректный формат error";

            throw new SimpleVkException($code, $message, $envelope);
        }

        $response = $envelope['response'] ?? null;

        if (!\is_array($response)) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "VK API вернул пустой или не-массивный response для {$method}",
                $envelope,
            );
        }

        $this->logger->debug('VK API ok', ['method' => $method]);

        return $response;
    }

    /**
     * Пауза между запросами при включённом rate limit.
     */
    private function throttle(): void
    {
        $limit = $this->config->rateLimitPerSecond;

        if ($limit === null || $limit <= 0) {
            return;
        }

        $minInterval = 1.0 / $limit;
        $now = \microtime(true);
        $wait = $this->lastRequestAt + $minInterval - $now;

        if ($wait > 0) {
            \usleep(\max(0, (int) \ceil($wait * 1_000_000)));
        }

        $this->lastRequestAt = \max($now, $this->lastRequestAt + $minInterval);
    }
}
