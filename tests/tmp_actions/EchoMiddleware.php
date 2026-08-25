<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\Context;
use DigitalStars\SimpleVK\Dispatcher\MiddlewareInterface;

class EchoMiddleware implements MiddlewareInterface
{
    public static int $hits = 0;

    public function process(Context $context, callable $next): void
    {
        ++self::$hits;
        $next($context);
    }
}
