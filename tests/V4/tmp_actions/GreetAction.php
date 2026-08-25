<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

#[Trigger(pattern: '/^привет (?P<name>\w+)$/iu')]
class GreetAction implements ActionInterface
{
    public static ?string $lastName = null;

    public function handle(Context $context, string $name): void
    {
        self::$lastName = $name;
    }
}
