<?php

namespace DigitalStars\SimpleVK\Attributes;

use Attribute;

/**
 * Помечает Action резервным обработчиком (когда ни один маршрут не подошёл).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Fallback
{
}
