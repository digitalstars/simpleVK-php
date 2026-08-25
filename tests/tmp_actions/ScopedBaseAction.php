<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\tmp_actions;

use DigitalStars\SimpleVK\Dispatcher\ActionInterface;
use DigitalStars\SimpleVK\Dispatcher\Attributes\UseMiddleware;

/**
 * Базовый Action, задающий scope: middleware наследуют все дети.
 */
#[UseMiddleware(EchoMiddleware::class)]
abstract class ScopedBaseAction implements ActionInterface {}
