<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Message;

use DigitalStars\SimpleVK\V4\ApiClient;

/**
 * Ответ на нажатие callback-кнопки (messages.sendMessageEventAnswer).
 *
 * Пример: CallbackAnswer::for($api, $eventId, $userId)->snackbar('Принято');
 */
final class CallbackAnswer
{
    private function __construct(
        private readonly ApiClient $api,
        private readonly string $eventId,
        private readonly int $userId,
    ) {}

    public static function for(ApiClient $api, string $eventId, int $userId): self
    {
        return new self($api, $eventId, $userId);
    }

    /**
     * Пустой ответ (убрать «часики» без сообщения).
     */
    public function empty(): void
    {
        $this->api->call('messages.sendMessageEventAnswer', [
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
        ]);
    }

    /**
     * Всплывающее сообщение поверх чата.
     */
    public function snackbar(string $text): void
    {
        $this->send(['type' => 'show_snackbar', 'text' => $text]);
    }

    /**
     * Открыть ссылку.
     */
    public function openLink(string $url): void
    {
        $this->send(['type' => 'open_link', 'link' => $url]);
    }

    /**
     * Открыть мини-приложение.
     */
    public function openApp(int $appId, ?int $ownerId = null, string $hash = ''): void
    {
        $payload = ['type' => 'open_app', 'app_id' => $appId, 'hash' => $hash];
        if ($ownerId !== null) {
            $payload['owner_id'] = $ownerId;
        }
        $this->send($payload);
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function send(array $eventData): void
    {
        $this->api->call('messages.sendMessageEventAnswer', [
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'event_data' => \json_encode($eventData, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ]);
    }
}
