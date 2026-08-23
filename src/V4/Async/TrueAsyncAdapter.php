<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Async;

use DigitalStars\SimpleVK\V4\Bot;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;

/**
 * EXPERIMENTAL: адаптер расширения true-async (php-async).
 *
 * Позволяет запускать блокирующий код бота (LongPoll через CurlTransport)
 * как корутину: основной поток продолжает работать, корутина планируется
 * нативным планировщиком расширения.
 *
 * Требует расширение true_async (https://true-async.github.io/, MIN ABI 0.24).
 *
 * Пример:
 *   $coroutine = TrueAsyncAdapter::run($bot); // неблокирующе
 *   ...основной поток...
 *   \Async\await($coroutine);                 // при необходимости дождаться
 */
final class TrueAsyncAdapter
{
    public static function available(): bool
    {
        return \extension_loaded('true_async') && \function_exists('Async\\spawn');
    }

    /**
     * Запускает LongPoll-цикл бота в отдельной true-async корутине.
     *
     * @return \Async\Coroutine Хендлер корутины (Awaitable): await()/cancel().
     */
    public static function run(Bot $bot): \Async\Coroutine
    {
        self::assertAvailable();

        return \Async\spawn(static function () use ($bot): void {
            $bot->run();
        });
    }

    /**
     * Произвольный callable в корутине true-async.
     *
     * @param callable(mixed...): mixed $task
     *
     * @return \Async\Coroutine
     */
    public static function spawn(callable $task, mixed ...$args): \Async\Coroutine
    {
        self::assertAvailable();

        /** @var \Async\Coroutine */
        return \Async\spawn($task, ...$args);
    }

    /**
     * Ожидает завершения корутины, пробрасывая её исключение.
     */
    public static function await(\Async\Coroutine $coroutine): mixed
    {
        return \Async\await($coroutine);
    }

    private static function assertAvailable(): void
    {
        if (!self::available()) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                'Адаптер true-async требует расширение ext-true_async (см. https://true-async.github.io/)',
            );
        }
    }
}
