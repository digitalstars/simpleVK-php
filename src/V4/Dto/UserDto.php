<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dto;

/**
 * Типизированный пользователь VK (users.get / from_id).
 *
 * Неизвестные поля игнорируются: конструктор принимает только то,
 * что пришло в массиве; отсутствующие строки равны null.
 */
final readonly class UserDto
{
    public function __construct(
        public int $id,
        public string $firstName = '',
        public string $lastName = '',
        /** Короткое имя (domain), например 'durov'. */
        public ?string $screenName = null,
    ) {}

    /**
     * @param array<string, mixed> $raw Элемент users.get или объект from_id-пользователя.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: (int) ($raw['id'] ?? 0),
            firstName: \is_string($raw['first_name'] ?? null) ? $raw['first_name'] : '',
            lastName: \is_string($raw['last_name'] ?? null) ? $raw['last_name'] : '',
            screenName: \is_string($raw['screen_name'] ?? null) ? $raw['screen_name'] : null,
        );
    }

    /**
     * Полное имя через пробел.
     */
    public function fullName(): string
    {
        return \trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * Упоминание в разметке VK: [id123|Имя].
     */
    public function mention(): string
    {
        $name = $this->firstName !== '' ? $this->firstName : $this->screenName ?? (string) $this->id;

        return "[id{$this->id}|{$name}]";
    }
}
