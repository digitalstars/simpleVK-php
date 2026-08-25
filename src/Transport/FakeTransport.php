<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Transport;

use DigitalStars\SimpleVK\Exception\SimpleVkException;

/**
 * Тестовая заглушка транспорта: canned-ответы вместо реальных вызовов VK API.
 * Не требует сети, записывает все вызовы для проверок.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, array{repeat: bool, items: list<mixed>}> */
    private array $responses = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $responses Метод => значение 'response'.
     */
    public function __construct(array $responses = [])
    {
        foreach ($responses as $method => $response) {
            $this->setResponse($method, $response);
        }
    }

    /**
     * Задаёт одиночный ответ метода (значение 'response' конверта).
     *
     * @param mixed $response Любое JSON-совместимое значение ответа VK API.
     */
    public function setResponse(string $method, mixed $response): self
    {
        $this->responses[$method] = ['repeat' => true, 'items' => [$response]];

        return $this;
    }

    /**
     * Задаёт очередь последовательных ответов (для тестов ретраев и повторных вызовов).
     *
     * @param list<mixed> $responses Ответы в порядке возврата; после исчерпания — исключение.
     */
    public function setResponseQueue(string $method, array $responses): self
    {
        $this->responses[$method] = ['repeat' => false, 'items' => \array_values($responses)];

        return $this;
    }

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        $entry = $this->responses[$method] ?? null;

        if ($entry === null || !$entry['repeat'] && $entry['items'] === []) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "FakeTransport: нет заготовленного ответа для метода {$method}",
            );
        }

        if ($entry['repeat']) {
            return ['response' => $entry['items'][0]];
        }

        return ['response' => \array_shift($this->responses[$method]['items'])];
    }

    /**
     * Все параметры последнего вызова метода.
     *
     * @return array<string, mixed>|null
     */
    public function lastParamsFor(string $method): ?array
    {
        for ($i = \count($this->calls) - 1; $i >= 0; --$i) {
            if ($this->calls[$i]['method'] === $method) {
                return $this->calls[$i]['params'];
            }
        }

        return null;
    }
}
