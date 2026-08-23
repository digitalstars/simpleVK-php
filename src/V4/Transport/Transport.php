<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Transport;

/**
 * Синхронный транспорт вызовов VK API.
 *
 * Реализации обязаны быть заменяемыми: ядро библиотеки не знает,
 * как именно выполняется HTTP-запрос (curl, PSR-18, Amp, тестовая заглушка).
 */
interface Transport
{
    /**
     * Выполняет метод VK API и возвращает полный конверт ответа
     * (например ['response' => [...]] или ['error' => [...]]).
     *
     * @param array<string, mixed> $params Параметры метода (значения уже нормализованы).
     *
     * @throws \DigitalStars\SimpleVK\V4\Exception\SimpleVkException При сетевом сбое или некорректном JSON.
     */
    public function call(string $method, array $params = []): array;
}
