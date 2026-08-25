<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(command: '/scoped')]
class ScopedChildAction extends ScopedBaseAction
{
    public static int $hits = 0;

    public function handle(Context $context): void
    {
        ++self::$hits;
    }
}
