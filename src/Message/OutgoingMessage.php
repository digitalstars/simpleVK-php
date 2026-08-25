<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Message;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Exception\SimpleVkException;

/**
 * Строитель исходящего сообщения (messages.send).
 *
 * Цепной API: $msg->text('...')->keyboard($kb)->send();
 * Длинные тексты автоматически разбиваются на несколько сообщений
 * с сохранением вложений и клавиатуры (клавиатура — в последнем).
 */
final class OutgoingMessage
{
    /** Практический лимит длины сообщения VK API. */
    public const int MAX_LENGTH = 4000;

    private ?string $text = null;
    private int|string|null $peerId = null;
    private ?int $userId = null;
    private ?int $randomId = null;

    /** @var list<string> */
    private array $attachments = [];

    private ?Keyboard $keyboard = null;
    private ?Carousel $carousel = null;
    private ?array $forward = null;
    private ?int $replyTo = null;
    private ?float $latitude = null;
    private ?float $longitude = null;
    private ?int $stickerId = null;
    private bool $dontParseLinks = false;
    private bool $disableMentions = false;

    public function __construct(
        private readonly ApiClient $api,
    ) {}

    public function to(int|string $peerId): self
    {
        $this->peerId = $peerId;

        return $this;
    }

    public function toUser(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Добавляет вложение: 'photo123_456', результат FileUploader и т.д.
     */
    public function attachment(string ...$attachments): self
    {
        foreach ($attachments as $attachment) {
            if ($attachment !== '') {
                $this->attachments[] = $attachment;
            }
        }

        return $this;
    }

    public function keyboard(Keyboard $keyboard): self
    {
        $this->keyboard = $keyboard;

        return $this;
    }

    public function carousel(Carousel $carousel): self
    {
        $this->carousel = $carousel;

        return $this;
    }

    /**
     * Пересылка сообщений: список message_id или массив параметров forward.
     *
     * @param list<int>|array<string, mixed> $forward
     */
    public function forward(array $forward): self
    {
        // list<int> → message_ids; ассоциативный массив — сырой forward-объект VK
        if (\array_is_list($forward)) {
            $this->forward = ['message_ids' => \implode(',', $forward)];
        } else {
            $this->forward = $forward;
        }

        return $this;
    }

    /**
     * Ответ на сообщение по его id.
     */
    public function replyTo(int $messageId): self
    {
        $this->replyTo = $messageId;

        return $this;
    }

    public function location(float $latitude, float $longitude): self
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function sticker(int $stickerId): self
    {
        $this->stickerId = $stickerId;

        return $this;
    }

    public function dontParseLinks(bool $value = true): self
    {
        $this->dontParseLinks = $value;

        return $this;
    }

    public function disableMentions(bool $value = true): self
    {
        $this->disableMentions = $value;

        return $this;
    }

    /**
     * Отправляет сообщение; при превышении лимита длины разбивает текст.
     *
     * @param int|string|null $peerId Адресат «на месте»: ->send($vkId) без ->to().
     *
     * @return list<int> ID отправленных сообщений.
     */
    public function send(int|string|null $peerId = null): array
    {
        if ($peerId !== null && $this->peerId === null && $this->userId === null) {
            $this->to($peerId);
        }

        $target = $this->resolveTarget();

        if (($this->text === null || $this->text === '') && $this->attachments === [] && $this->stickerId === null) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                'Пустое сообщение: задайте text/attachment/sticker',
            );
        }

        $chunks = self::splitText($this->text ?? '');
        $sentIds = [];

        foreach ($chunks as $index => $chunk) {
            $params = $target;
            $params['message'] = $chunk;

            // Клавиатура/карусель отправляются в последней части, чтобы не дублировать
            $isLastChunk = $index === (\count($chunks) - 1);

            if ($isLastChunk && $this->keyboard !== null && !$this->keyboard->isEmpty()) {
                $params['keyboard'] = \json_encode(
                    $this->keyboard,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                );
            }

            if ($isLastChunk && $this->carousel !== null && !$this->carousel->isEmpty()) {
                $params['template'] = \json_encode(
                    $this->carousel,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                );
            }

            if (\count($this->attachments) > 0) {
                $params['attachment'] = \implode(',', $this->attachments);
            }

            if ($this->forward !== null) {
                foreach ($this->forward as $key => $value) {
                    $params[$key] = $value;
                }
            }

            if ($this->replyTo !== null) {
                $params['reply_to'] = $this->replyTo;
            }

            if ($this->latitude !== null && $this->longitude !== null) {
                $params['lat'] = $this->latitude;
                $params['long'] = $this->longitude;
            }

            if ($this->stickerId !== null) {
                $params['sticker_id'] = $this->stickerId;
            }

            if ($this->dontParseLinks) {
                $params['dont_parse_links'] = 1;
            }

            if ($this->disableMentions) {
                $params['disable_mentions'] = 1;
            }

            // random_id обязателен: без него VK дедуплицирует одинаковые сообщения
            $response = $this->api->call('messages.send', [
                ...$params,
                'random_id' => $this->randomId ?? \random_int(\PHP_INT_MIN, \PHP_INT_MAX),
            ]);

            $messageId = $response[0]['message_id'] ?? $response['message_id'] ?? null;
            if ($messageId !== null) {
                $sentIds[] = (int) $messageId;
            }
        }

        return $sentIds;
    }

    /**
     * Ищет границу разреза: абзац -> строка -> слово -> жёсткий лимит.
     */
    private static function findCut(string $slice): int
    {
        foreach (["\n\n", "\n", ' '] as $needle) {
            $position = \mb_strrpos($slice, $needle);
            if ($position !== false && $position > 0) {
                return $position;
            }
        }

        return self::MAX_LENGTH;
    }

    /**
     * Редактирование ранее отправленного сообщения (messages.edit).
     *
     * @return bool Успешно ли отредактировано.
     */
    public function edit(int|string $conversationMessageId): bool
    {
        $params = [
            'conversation_message_id' => $conversationMessageId,
            'message' => $this->text ?? '',
        ];

        if ($this->peerId !== null) {
            $params['peer_id'] = $this->peerId;
        }

        if ($this->keyboard !== null && !$this->keyboard->isEmpty()) {
            $params['keyboard'] = \json_encode($this->keyboard, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        if ($this->attachments !== []) {
            $params['attachment'] = \implode(',', $this->attachments);
        }

        try {
            $this->api->call('messages.edit', $params);

            return true;
        } catch (SimpleVkException $e) {
            // VK возвращает 100/909/917 при невозможности правки — это штатный false
            if (\in_array($e->vkErrorCode, [100, 908, 909, 910, 911, 912, 913, 914, 915, 916, 917], true)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Разбивает длинный текст по границам абзацев/слов/символов.
     *
     * @return list<string>
     */
    public static function splitText(string $text): array
    {
        if (\mb_strlen($text) <= self::MAX_LENGTH) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (\mb_strlen($remaining) > self::MAX_LENGTH) {
            // Ищем границу абзаца, затем слова, затем жёсткий разрез
            $slice = \mb_substr($remaining, 0, self::MAX_LENGTH);
            $cut = self::findCut($slice);
            $chunks[] = \trim(\mb_substr($remaining, 0, $cut));
            $remaining = \ltrim(\mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTarget(): array
    {
        if ($this->peerId !== null) {
            return ['peer_id' => $this->peerId];
        }

        if ($this->userId !== null) {
            return ['user_id' => $this->userId];
        }

        throw new SimpleVkException(
            SimpleVkException::TRANSPORT_ERROR,
            'Не указан получатель: вызовите ->to() или ->toUser()',
        );
    }
}
