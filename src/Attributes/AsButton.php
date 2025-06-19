<?php
namespace DigitalStars\SimpleVK\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsButton
{
    /**
     * @param string $label Текст на кнопке.
     * @param string|null $payload Уникальный ID для payload. Если null, используется короткое имя класса.
     * @param string $color Цвет кнопки: 'blue', 'red', 'white', 'green'.
     * @param string $type Тип кнопки: 'callback' или 'text'.
     */
    public function __construct(
        public string $label = 'Базовая кнопка',
        public ?string $payload = null,
        public string $color = 'blue',
        public string $type = 'text'
    ) {}
}