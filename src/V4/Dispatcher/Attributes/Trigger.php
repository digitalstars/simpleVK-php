<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dispatcher\Attributes;

use Attribute;

/**
 * Триггеры запуска Action: текстовая команда и/или регулярное выражение.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Trigger
{
    /**
     * @param string|null $command Текстовая команда с опциональными плейсхолдерами.
     * @param string|null $pattern Регулярное выражение.
     */
    public function __construct(
        public ?string $command = null,
        public ?string $pattern = null,
    ) {}
}
