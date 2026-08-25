<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\Dispatcher\Attributes\OnException;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[OnException(\RuntimeException::class)]
class ExceptionHandlerAction implements ActionInterface
{
    public static ?\Throwable $lastException = null;

    public function handle(Context $context, \Throwable $exception): void
    {
        self::$lastException = $exception;
    }
}
