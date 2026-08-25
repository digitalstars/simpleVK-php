<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Dispatcher\Attributes;

use Attribute;

/**
 * Помечает Action резервным обработчиком (когда ни один маршрут не подошёл).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Fallback {}
