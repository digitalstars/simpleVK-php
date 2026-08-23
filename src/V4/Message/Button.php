<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Message;

/**
 * Кнопка клавиатуры VK (текстовая, callback, ссылка и др.).
 *
 * Строится фабричными методами; toArray() даёт структуру для messages.send.
 */
final class Button implements \JsonSerializable
{
    public const string COLOR_PRIMARY = 'primary';
    public const string COLOR_SECONDARY = 'secondary';
    public const string COLOR_NEGATIVE = 'negative';
    public const string COLOR_POSITIVE = 'positive';

    /** @var array<string, mixed> */
    private array $action;
    private ?string $color = null;

    private function __construct(array $action)
    {
        $this->action = $action;
    }

    public static function text(string $label, array|string|null $payload = null): self
    {
        return new self([
            'type' => 'text',
            'label' => $label,
            'payload' => self::encodePayload($payload),
        ]);
    }

    public static function callback(string $label, array|string|null $payload = null): self
    {
        return new self([
            'type' => 'callback',
            'label' => $label,
            'payload' => self::encodePayload($payload),
        ]);
    }

    public static function link(string $label, string $url): self
    {
        return new self(['type' => 'open_link', 'label' => $label, 'link' => $url]);
    }

    public static function location(string $payloadJson): self
    {
        return new self(['type' => 'location', 'payload' => $payloadJson]);
    }

    public static function vkPay(string $payloadJson, ?string $hash = null): self
    {
        $action = ['type' => 'vkpay', 'hash' => $hash ?? ''];
        if ($payloadJson !== '') {
            $action['payload'] = $payloadJson;
        }

        return new self($action);
    }

    public static function openApp(int $appId, int $ownerId, string $label = 'Открыть'): self
    {
        return new self(['type' => 'open_app', 'app_id' => $appId, 'owner_id' => $ownerId, 'label' => $label]);
    }

    /**
     * Цвет только для текстовых/callback-кнопок.
     */
    public function color(string $color): self
    {
        $clone = clone $this;
        $clone->color = $color;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $button = ['action' => $this->action];

        if ($this->color !== null && \in_array($this->action['type'], ['text', 'callback'], true)) {
            $button['color'] = $this->color;
        }

        return $button;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function encodePayload(array|string|null $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (\is_string($payload)) {
            return $payload;
        }

        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
