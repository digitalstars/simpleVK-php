<?php

namespace DigitalStars\SimpleVK\V4\Message;

/**
 * Карусель VK: до 10 карточек (title, описание, фото, кнопки).
 */
final class Carousel implements \JsonSerializable
{
    private const int MAX_ELEMENTS = 10;

    /** @var list<array<string, mixed>> */
    private array $elements = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param list<Button> $buttons До 3 кнопок на карточку.
     */
    public function element(
        string $title,
        ?string $description = null,
        ?string $photoId = null,
        array $buttons = [],
    ): self {
        if (\count($this->elements) >= self::MAX_ELEMENTS) {
            throw new \LogicException('В карусели не может быть больше ' . self::MAX_ELEMENTS . ' карточек');
        }

        $card = [
            'title' => $title,
            'action' => ['type' => 'open_photo'],
        ];

        if ($description !== null && $description !== '') {
            $card['description'] = $description;
        }

        if ($photoId !== null) {
            $card['photo_id'] = $photoId;
        }

        $card['buttons'] = \array_map(static fn(Button $b): array => $b->toArray(), $buttons);

        $this->elements[] = $card;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    /**
     * @return array{type: string, elements: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['type' => 'carousel', 'elements' => $this->elements];
    }

    /**
     * @return array{type: string, elements: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
