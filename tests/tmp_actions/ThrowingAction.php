<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(command: '/boom')]
class ThrowingAction implements ActionInterface
{
    public function handle(Context $context): void
    {
        throw new \RuntimeException('взрыв');
    }
}
