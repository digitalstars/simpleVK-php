<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4\tmp_actions;

use DigitalStars\SimpleVK\V4\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\UseMiddleware;

/**
 * Базовый Action, задающий scope: middleware наследуют все дети.
 */
#[UseMiddleware(EchoMiddleware::class)]
abstract class ScopedBaseAction implements ActionInterface {}
