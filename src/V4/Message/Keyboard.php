<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Message;

use DigitalStars\SimpleVK\V4\Dispatcher\BaseButton;

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
    private const int MAX_PER_ROW = 10;
    private const int MAX_ROWS = 10;

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
     * Мост для EventDispatcher: строит клавиатуру из массива строк экземпляров
     * кнопок-классов (наследников BaseButton). label/color/payload/type берутся
     * из состояния объекта и атрибута #[AsButton].
     *
     * @param array<list<mixed>> $rows Строки кнопок; элементы проверяются рантайм-гвардом.
     */
    public static function fromButtons(array $rows): self
    {
        $keyboard = new self();

        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $button) {
                if (!$button instanceof BaseButton) {
                    throw new \LogicException('В строке клавиатуры ожидается экземпляр ' . BaseButton::class);
                }

                $payload = $button->getPayload();
                $action = $payload['action'] ?? self::defaultPayloadAction($button::class);
                $payload += ['action' => $action];

                $type = \strtolower($button->getType() ?? 'text');
                $label = $button->getLabel() ?? 'Кнопка';

                $vkButton = match ($type) {
                    'callback' => Button::callback($label, $payload),
                    default => Button::text($label, $payload),
                };

                $colorMap = [
                    'blue' => Button::COLOR_PRIMARY,
                    'white' => Button::COLOR_SECONDARY,
                    'red' => Button::COLOR_NEGATIVE,
                    'green' => Button::COLOR_POSITIVE,
                    'primary' => Button::COLOR_PRIMARY,
                    'secondary' => Button::COLOR_SECONDARY,
                    'negative' => Button::COLOR_NEGATIVE,
                    'positive' => Button::COLOR_POSITIVE,
                ];
                $color = $colorMap[\strtolower($button->getColor() ?? 'blue')] ?? null;
                if ($color !== null) {
                    $vkButton = $vkButton->color($color);
                }

                $buttons[] = $vkButton;
            }

            $keyboard->row(...$buttons);
        }

        return $keyboard;
    }

    /**
     * Дефолтный payload-action из короткого имени класса (как в v3).
     */
    private static function defaultPayloadAction(string $className): string
    {
        $pos = \strrpos($className, '\\');

        return $pos === false ? $className : \substr($className, $pos + 1);
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
