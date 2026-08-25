<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_throttled;

use DigitalStars\SimpleVK\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Throttle;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(command: '/limited')]
#[Throttle(max: 2, windowSeconds: 60)]
class LimitedAction implements ActionInterface
{
    public static int $hits = 0;

    public function handle(Context $context): void
    {
        ++self::$hits;
    }
}
