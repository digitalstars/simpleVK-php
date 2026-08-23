<?php

namespace DigitalStars\SimpleVK\V4\Dispatcher;

/**
 * Базовый класс кнопок для EventDispatcher: участвует в #[AsButton]-роутинге
 * и рендерится в кнопку VK клавиатуры.
 */
abstract class BaseButton extends BaseAction
{
    protected ?string $label = null;
    protected ?string $color = null;
    protected ?string $type = 'text';
    /** @var array<string, mixed> */
    protected array $payload = [];

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function color(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Задаёт/дополняет payload кнопки (ключ action резервируется диспетчером).
     *
     * @param array<string, mixed> $payload
     */
    public function addPayload(array $payload, bool $merge = true): self
    {
        $this->payload = $merge ? array_merge($this->payload, $payload) : $payload;
        return $this;
    }
}
