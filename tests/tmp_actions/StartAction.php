<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\BaseAction;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Trigger(command: '/start')]
class StartAction extends BaseAction
{
    public static int $hits = 0;

    public function handle(Context $ctx): void
    {
        ++self::$hits;
        $ctx->msg('start-ok')->send();
    }
}
