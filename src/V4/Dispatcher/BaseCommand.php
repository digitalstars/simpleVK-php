<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dispatcher;

/**
 * Action, запускаемый текстовой командой (#[Trigger(command: ...)]) или #[Fallback].
 */
abstract class BaseCommand extends BaseAction {}
