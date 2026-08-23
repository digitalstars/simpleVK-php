<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dto;

/**
 * Типизированное входящее сообщение VK (объект message из update).
 *
 * Тонкая обёртка над сырым массивом: поля читаются лениво и типизированно,
 * сырые данные доступны через raw().
 */
final readonly class MessageDto
{
    /**
     * @param array<string, mixed> $raw
     * @param list<array{type: string, id: string}> $attachments
     */
    public function __construct(
        public int $id,
        public ?UserDto $from,
        public int $peerId,
        public string $text,
        /** Payload кнопки, если сообщение отправлено через bot-button. */
        public ?array $payload,
        /** ID сообщения, на которое отвечают. */
        public ?int $replyToMessageId,
        public array $attachments,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $raw Сырой объект message.
     */
    public static function fromArray(array $raw): self
    {
        $payload = null;
        if (\is_string($raw['payload'] ?? null) && $raw['payload'] !== '') {
            try {
                $decoded = \json_decode($raw['payload'], true, flags: \JSON_THROW_ON_ERROR);
                $payload = \is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                $payload = null;
            }
        }

        return new self(
            id: (int) ($raw['id'] ?? 0),
            from: isset($raw['from_id']) && (int) $raw['from_id'] > 0
                ? UserDto::fromArray(['id' => (int) $raw['from_id']])
                : null,
            peerId: (int) ($raw['peer_id'] ?? 0),
            text: \is_string($raw['text'] ?? null) ? $raw['text'] : '',
            payload: $payload,
            replyToMessageId: self::resolveReplyTo($raw),
            attachments: self::parseAttachments($raw),
            raw: $raw,
        );
    }

    /**
     * Текст или подпись медиа.
     */
    public function effectiveText(): string
    {
        return $this->text !== '' ? $this->text : (string) ($this->raw['caption'] ?? '');
    }

    public function isReply(): bool
    {
        return $this->replyToMessageId !== null;
    }

    private static function resolveReplyTo(array $raw): ?int
    {
        if (isset($raw['reply_message']['id'])) {
            return (int) $raw['reply_message']['id'];
        }

        return isset($raw['reply_to']) && \is_numeric($raw['reply_to']) ? (int) $raw['reply_to'] : null;
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    private static function parseAttachments(array $raw): array
    {
        $result = [];
        foreach ($raw['attachments'] ?? [] as $attachment) {
            if (!\is_array($attachment)) {
                continue;
            }
            $type = (string) ($attachment['type'] ?? '');
            $item = \is_array($attachment[$type] ?? null) ? $attachment[$type] : null;
            if ($item === null) {
                continue;
            }

            $accessKey = \is_string($item['access_key'] ?? null) ? '_' . $item['access_key'] : '';
            $result[] = [
                'type' => $type,
                'id' => \sprintf('%d_%d%s', (int) ($item['owner_id'] ?? 0), (int) ($item['id'] ?? 0), $accessKey),
            ];
        }

        return $result;
    }
}
