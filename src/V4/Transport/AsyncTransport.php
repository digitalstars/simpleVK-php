<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Transport;

use Amp\Future;

/**
 * Асинхронный транспорт вызовов VK API поверх event loop (Revolt/Amp).
 *
 * Контракт совпадает с Transport::call(), но возвращает Amp\Future.
 * Ядро зависит только от amphp/amp (Promise-совместимость), поэтому
 * один и тот же код обработчиков работает и синхронно, и на event loop.
 */
interface AsyncTransport
{
    /**
     * @param array<string, mixed> $params Параметры метода (значения уже нормализованы).
     *
     * @return Future<array<string, mixed>> Полный конверт ответа VK API.
     */
    public function callAsync(string $method, array $params = []): Future;
}
