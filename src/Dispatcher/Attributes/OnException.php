<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Dispatcher\Attributes;

use Attribute;

/**
 * Маршрутизация исключений: если Action (или его middleware) бросил исключение
 * совместимого класса, управление получает указанный Action.
 *
 * Выбирается самый специфичный обработчик: для RuntimeException будет найден
 * обработчик RuntimeException раньше, чем обработчик Throwable/Exception.
 *
 * В обработчике исключение доступно параметром с именем $exception:
 *   public function handle(Context $ctx, \Throwable $exception): void
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class OnException
{
    /**
     * @param class-string<\Throwable> $exception
     */
    public function __construct(
        public readonly string $exception,
    ) {
        if (!\is_a($this->exception, \Throwable::class, true)) {
            throw new \LogicException(
                "#[OnException]: '{$this->exception}' должен быть классом, реализующим Throwable",
            );
        }
    }
}
