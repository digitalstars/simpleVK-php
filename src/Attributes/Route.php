<?php

namespace DigitalStars\SimpleVK\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Route
{
    public ?string $path;
    public ?string $name;
    public ?string $pattern;

    /**
     * @param ?string $path Текстовая команда с плейсхолдерами (%s, %i, %user). Например: '/kick %user'.
     * @param ?string $name Имя для триггера по кнопке. Например: 'kick_member'.
     * @param ?string $pattern Регулярное выражение. Например: '/^предмет\s+(\d+)/i'.
     */
    public function __construct(?string $path = null, ?string $name = null, ?string $pattern = null)
    {
        $this->path = $path;
        $this->name = $name;
        $this->pattern = $pattern;
    }
}