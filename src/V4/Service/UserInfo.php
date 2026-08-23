<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Service;

use DigitalStars\SimpleVK\V4\ApiClient;

/**
 * Сервис данных о пользователях и правах (аналог userInfo/isAdmin v3).
 *
 * Выделен из ядра: доменные хелперы не место в транспортном слое.
 */
final class UserInfo
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly int $groupId,
    ) {}

    /**
     * Данные пользователя users.get.
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    public function info(int $userId, array $fields = ['first_name', 'last_name', 'screen_name']): array
    {
        $result = $this->api->call('users.get', [
            'user_ids' => $userId,
            'fields' => \implode(',', $fields),
        ]);

        /** @var array<string, mixed>|null $first */
        $first = \array_values($result)[0] ?? null;

        return \is_array($first) ? $first : [];
    }

    /**
     * Имя для упоминания '[id123|Имя]' — VK сам отрендерит ссылку.
     */
    public function mention(int $userId, ?string $displayName = null): string
    {
        $name = $displayName ?? $this->info($userId)['first_name'] ?? '';

        return "[id{$userId}|{$name}]";
    }

    /**
     * Является ли пользователь администратором/редактором сообщества.
     */
    public function isAdmin(int $userId): bool
    {
        if ($this->groupId <= 0) {
            return false;
        }

        try {
            $result = $this->api->call('groups.isMember', [
                'group_id' => $this->groupId,
                'user_id' => $userId,
                'filter' => 'administrator',
            ]);
        } catch (\DigitalStars\SimpleVK\V4\Exception\SimpleVkException) {
            return false;
        }

        return (bool) ($result['member'] ?? false);
    }

    /**
     * Подписан ли пользователь на сообщество.
     */
    public function isMember(int $userId): bool
    {
        if ($this->groupId <= 0) {
            return false;
        }

        try {
            $result = $this->api->call('groups.isMember', [
                'group_id' => $this->groupId,
                'user_id' => $userId,
            ]);
        } catch (\DigitalStars\SimpleVK\V4\Exception\SimpleVkException) {
            return false;
        }

        return (bool) ($result['member'] ?? false);
    }

    /**
     * Поддержка клиента в диалоге: включены ли сообщения от группы пользователю.
     * Возвращает client_info-флаги последнего известного состояния (из события).
     *
     * @param array<string, mixed> $clientInfo Поле client_info из message_new.
     * @param string $feature Ключ: keyboard, inline_keyboard, carousel и т.д.
     */
    public static function supports(array $clientInfo, string $feature): bool
    {
        return (bool) ($clientInfo[$feature] ?? false);
    }
}
