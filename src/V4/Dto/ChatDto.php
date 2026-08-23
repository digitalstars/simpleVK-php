<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dto;

/**
 * Типизированный чат/диалог VK (по peer_id).
 *
 * Схема peer_id VK: пользователь = его id,
 * сообщество = -id, беседа = 2000000000 + id.
 */
final readonly class ChatDto
{
    private const int PEER_CHAT_OFFSET = 2_000_000_000;

    public function __construct(
        /** peer_id диалога. */
        public int $id,
        /** 'user' | 'group' | 'chat'. */
        public string $type,
        /** Название сообщества/беседы; для личного диалога null. */
        public ?string $title = null,
    ) {}

    /**
     * Разбирает peer_id без обращения к API.
     */
    public static function fromPeerId(int $peerId): self
    {
        if ($peerId >= self::PEER_CHAT_OFFSET) {
            return new self(id: $peerId, type: 'chat', title: null);
        }

        if ($peerId < 0) {
            return new self(id: $peerId, type: 'group', title: null);
        }

        return new self(id: $peerId, type: 'user', title: null);
    }

    /**
     * Базовый id сущности без знака и смещения:
     * peer 2000000005 → 5, peer -42 → 42, peer 100 → 100.
     */
    public function entityId(): int
    {
        return \abs($this->id - ($this->type === 'chat' ? self::PEER_CHAT_OFFSET : 0));
    }

    /**
     * Отображаемое имя: title, иначе 'Пользователь {id}'.
     */
    public function displayName(): string
    {
        return $this->title ?? "Пользователь {$this->id}";
    }
}
