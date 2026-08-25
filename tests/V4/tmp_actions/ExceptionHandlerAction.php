<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\OnException;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

#[OnException(\RuntimeException::class)]
class ExceptionHandlerAction implements ActionInterface
{
    public static ?\Throwable $lastException = null;

    public function handle(Context $context, \Throwable $exception): void
    {
        self::$lastException = $exception;
    }
}
