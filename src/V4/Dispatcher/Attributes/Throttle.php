<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dispatcher\Attributes;

use Attribute;

/**
 * Ограничение частоты срабатывания Action на одного пользователя.
 *
 * Требует PSR-16 кэш в EventDispatcher (4-й аргумент конструктора);
 * при его отсутствии регистрация Action с #[Throttle] падает fail-fast.
 *
 * При превышении лимита событие молча отбрасывается (в debug-режиме — E_USER_WARNING).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Throttle
{
    public function __construct(
        /** Максимум вызовов от одного пользователя за окно. */
        public readonly int $max,
        /** Размер окна в секундах. */
        public readonly int $windowSeconds = 60,
    ) {
        if ($this->max < 1 || $this->windowSeconds < 1) {
            throw new \LogicException('#[Throttle]: max и windowSeconds должны быть >= 1');
        }
    }
}
