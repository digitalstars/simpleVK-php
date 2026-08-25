<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

#[Trigger(command: '/scoped')]
class ScopedChildAction extends ScopedBaseAction
{
    public static int $hits = 0;

    public function handle(Context $context): void
    {
        ++self::$hits;
    }
}
