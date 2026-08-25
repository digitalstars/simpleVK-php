<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\Attributes\Fallback;
use DigitalStars\SimpleVK\Dispatcher\BaseAction;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[Fallback]
class CatchAllAction extends BaseAction
{
    public static ?string $lastText = null;

    public function handle(Context $ctx): void
    {
        self::$lastText = $ctx->messageText;
    }
}
