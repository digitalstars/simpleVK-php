<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\Attributes\AsButton;
use DigitalStars\SimpleVK\Dispatcher\BaseButton;
use DigitalStars\SimpleVK\Dispatcher\Context;

#[AsButton(label: 'Тестовая кнопка', color: 'green')]
class PingBtn extends BaseButton
{
    public static ?Context $lastContext = null;
    public static ?array $lastArgs = null;
    public static int $hits = 0;

    public function handle(Context $ctx, ?string $extra = null): void
    {
        self::$lastContext = $ctx;
        self::$lastArgs = ['extra' => $extra];
        ++self::$hits;
        $ctx->msg('pong')->send();
    }
}
