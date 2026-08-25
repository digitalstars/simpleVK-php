<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(pattern: '/^привет (?P<name>\w+)$/iu')]
class GreetAction implements ActionInterface
{
    public static ?string $lastName = null;

    public function handle(Context $context, string $name): void
    {
        self::$lastName = $name;
    }
}
