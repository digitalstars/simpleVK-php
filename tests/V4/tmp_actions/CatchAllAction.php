<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Fallback;
use DigitalStars\SimpleVK\V4\Dispatcher\BaseAction;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;

#[Fallback]
class CatchAllAction extends BaseAction
{
    public static ?string $lastText = null;

    public function handle(Context $ctx): void
    {
        self::$lastText = $ctx->messageText;
    }
}
