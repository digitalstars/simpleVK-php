<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_throttled;

use DigitalStars\SimpleVK\V4\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Throttle;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

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
