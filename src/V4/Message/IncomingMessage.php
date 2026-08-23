<?php

namespace DigitalStars\SimpleVK\V4\Message;

use DigitalStars\SimpleVK\V4\ApiClient;

/**
 * Типизированная обёртка входящего сообщения (update message_new).
 *
 * Даёт удобный доступ к полям, парсинг вложений и быстрый ответ.
 */
final class IncomingMessage
{
    /** @var array<string, mixed> Сырой объект message. */
    private readonly array $message;

    /** @var array<string, mixed> client_info из update. */
    private readonly array $clientInfo;

    /**
     */
    public function __construct(
        private readonly \DigitalStars\SimpleVK\V4\Event\Update $update,
        private readonly ApiClient $api,
        private readonly int $groupId,
    ) {
        $object = $update->object();
        $this->message = \is_array($object['message'] ?? null) ? $object['message'] : $object;
        $this->clientInfo = \is_array($object['client_info'] ?? null) ? $object['client_info'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->message;
    }

    /**
     * API-клиент, привязанный к событию (для Context).
     */
    public function api(): ApiClient
    {
        return $this->api;
    }

    public function messageId(): int
    {
        return (int) ($this->message['id'] ?? 0);
    }

    /**
     * ID пользователя-отправителя (или - сообщества).
     */
    public function senderId(): int
    {
        return (int) ($this->message['from_id'] ?? 0);
    }

    public function peerId(): int
    {
        return (int) ($this->message['peer_id'] ?? 0);
    }

    /**
     * Payload кнопки, если сообщение отправлено нажатием bot-button.
     *
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $payload = $this->message['payload'] ?? null;

        if (!\is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decoded = \json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Текст сообщения (геттер) или строитель ответа с этим текстом.
     *
     * Двойной режим как в v3:
     *   $t = $msg->text();                 // получить текст
     *   $msg->text('ответ')->send();       // отправить ответ в диалог
     */
    public function text(?string $newText = null): string|OutgoingMessage
    {
        if ($newText === null) {
            return (string) ($this->message['text'] ?? '');
        }

        return (new OutgoingMessage($this->api))->to($this->peerId())->text($newText);
    }

    /**
     * Вложения сообщения: ['type' => 'photo', 'id' => '123_456', ...].
     *
     * @return list<array{type: string, id: string}>
     */
    public function attachments(): array
    {
        $result = [];

        foreach ($this->message['attachments'] ?? [] as $attachment) {
            if (!\is_array($attachment)) {
                continue;
            }
            $type = (string) ($attachment['type'] ?? '');
            $item = $attachment[$type] ?? null;

            if (\is_array($item)) {
                $ownerId = (int) ($item['owner_id'] ?? 0);
                $objectId = (int) ($item['id'] ?? 0);
                $accessKey = isset($item['access_key']) && \is_string($item['access_key'])
                    ? '_' . $item['access_key']
                    : '';
                $result[] = ['type' => $type, 'id' => "{$ownerId}_{$objectId}{$accessKey}"];
            }
        }

        return $result;
    }

    /**
     * ID вложений указанного типа: attachment('photo') => ['123_456', ...].
     *
     * @return list<string>
     */
    public function attachmentsByType(string $type): array
    {
        return \array_values(\array_map(
            static fn(array $a): string => $a['id'],
            \array_filter($this->attachments(), static fn(array $a): bool => $a['type'] === $type),
        ));
    }

    public function hasText(): bool
    {
        return $this->text() !== '';
    }

    public function isFromUser(): bool
    {
        return $this->senderId() > 0;
    }

    public function isFromGroup(): bool
    {
        return $this->senderId() < 0;
    }

    /**
     * Это сообщение из личного диалога с ботом?
     */
    public function isPrivate(): bool
    {
        return $this->peerId() === $this->senderId();
    }

    /**
     * Быстрый ответ в тот же диалог.
     */
    public function reply(string $text): OutgoingMessage
    {
        return $this->outgoing()->text($text)->replyTo($this->messageId());
    }

    /**
     * Пустой строитель исходящего сообщения для этого диалога.
     */
    public function outgoing(): OutgoingMessage
    {
        return (new OutgoingMessage($this->api))->to($this->peerId());
    }
}
