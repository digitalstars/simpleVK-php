<?php

namespace DigitalStars\SimpleVK\V4\Dispatcher\Attributes;

use Attribute;

/**
 * Помечает Action резервным обработчиком (когда ни один маршрут не подошёл).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Fallback {}
