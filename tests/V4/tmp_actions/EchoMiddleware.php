<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\Context;
use DigitalStars\SimpleVK\V4\Dispatcher\MiddlewareInterface;

class EchoMiddleware implements MiddlewareInterface
{
    public static int $hits = 0;

    public function process(Context $context, callable $next): void
    {
        ++self::$hits;
        $next($context);
    }
}
