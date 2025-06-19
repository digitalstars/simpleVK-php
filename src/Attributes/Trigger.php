<?php
namespace DigitalStars\SimpleVK\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Trigger
{
    /**
     * @param string|null $command Текстовая команда с опциональными плейсхолдерами.
     * @param string|null $pattern Регулярное выражение.
     */
    public function __construct(
        public ?string $command = null,
        public ?string $pattern = null
    ) {}
}