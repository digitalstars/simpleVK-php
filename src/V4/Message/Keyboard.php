<?php

namespace DigitalStars\SimpleVK\V4\Message;

/**
 * Клавиатура VK: строки кнопок, one-time/inline режимы.
 *
 * Пример:
 *   Keyboard::make()
 *       ->row(Button::text('Справка')->color(Button::COLOR_PRIMARY))
 *       ->inline()
 *       ->toArray();
 */
final class Keyboard implements \JsonSerializable
{
    /** Максимум кнопок в строке (ограничение VK API). */
    private const MAX_PER_ROW = 10;
    private const MAX_ROWS = 10;

    /** @var list<list<Button>> */
    private array $rows = [];

    private bool $oneTime = false;
    private bool $inline = false;

    public static function make(): self
    {
        return new self();
    }

    /**
     * Добавляет строку с кнопками; без аргументов начинает новую пустую строку.
     */
    public function row(Button ...$buttons): self
    {
        if (\count($this->rows) >= self::MAX_ROWS) {
            throw new \LogicException('В клавиатуре не может быть больше ' . self::MAX_ROWS . ' строк');
        }

        if (\count($buttons) > self::MAX_PER_ROW) {
            throw new \LogicException('В строке клавиатуры не может быть больше ' . self::MAX_PER_ROW . ' кнопок');
        }

        $this->rows[] = \array_values($buttons);

        return $this;
    }

    /**
     * Клавиатура скрывается после нажатия.
     */
    public function oneTime(): self
    {
        $this->oneTime = true;

        return $this;
    }

    /**
     * Клавиатура внутри сообщения вместо под полем ввода.
     */
    public function inline(): self
    {
        $this->inline = true;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return array{one_time: bool, inline: bool, buttons: list<list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        return [
            'one_time' => $this->oneTime,
            'inline' => $this->inline,
            'buttons' => \array_map(static fn(array $row): array => \array_map(
                static fn(Button $b): array => $b->toArray(),
                $row,
            ), $this->rows),
        ];
    }

    /**
     * @return array{one_time: bool, inline: bool, buttons: list<list<array<string, mixed>>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
