<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

#[Trigger(command: '/boom')]
class ThrowingAction implements ActionInterface
{
    public function handle(Context $context): void
    {
        throw new \RuntimeException('взрыв');
    }
}
