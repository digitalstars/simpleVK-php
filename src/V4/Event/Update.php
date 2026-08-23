<?php

namespace DigitalStars\SimpleVK\V4\Event;

/**
 * Базовый DTO события (update) VK API.
 *
 * Хранит исходный сырой массив — типизированные обёртки
 * (MessageNew, CallbackEvent и т.д.) строятся поверх него.
 */
readonly class Update
{
    /**
     * @param array<string, mixed> $raw Полный объект update из LongPoll/Callback.
     */
    final public function __construct(
        public UpdateType $type,
        public int $eventId,
        /** ID группы, из которой пришло событие. */
        public int $groupId,
        public array $raw,
    ) {}

    /**
     * Фабрика из сырого update LongPoll-ответа.
     *
     * @param array<string, mixed> $raw Ожидает ключи type/event_id/group_id/object.
     */
    public static function fromLongPoll(array $raw): self
    {
        return new static(
            type: UpdateType::fromString(\is_string($raw['type'] ?? null) ? $raw['type'] : 'unknown'),
            eventId: (int) ($raw['event_id'] ?? 0),
            groupId: (int) ($raw['group_id'] ?? 0),
            raw: $raw,
        );
    }

    /**
     * Объект события ('object' / 'object.message' в зависимости от типа).
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $object = $this->raw['object'] ?? [];

        // Для message_new/message_reply/message_edit VK кладёт сообщение в object.message
        if (\is_array($object) && isset($object['message']) && \is_array($object['message'])) {
            return $object;
        }

        return \is_array($object) ? $object : [];
    }
}
