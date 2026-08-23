<?php

namespace DigitalStars\SimpleVK\V4\Service;

/**
 * Форматирование упоминаний в тексте сообщений.
 *
 * VK-разметка: [id123|Имя] — упоминание пользователя,
 * [club456|Группа] — сообщества.
 */
final class Mentions
{
    /**
     * Упоминание пользователя по ID и имени.
     */
    public static function user(int $userId, string $displayName): string
    {
        return "[id{$userId}|{$displayName}]";
    }

    /**
     * Упоминание сообщества.
     */
    public static function group(int $groupId, string $displayName): string
    {
        // Отрицательный ID → club
        if ($groupId < 0) {
            return '[club' . \abs($groupId) . "|{$displayName}]";
        }

        return "[club{$groupId}|{$displayName}]";
    }

    /**
     * Заменяет @screen_name на VK-упоминания (аналог placeholders v3).
     *
     * Пример: '@petr, привет' → '[id123|Пётр], привет'
     *
     * @param array<string, string> $mentionsMap '@screen_name' => 'Имя или id123'
     */
    public static function replaceScreenNames(string $text, array $mentionsMap): string
    {
        foreach ($mentionsMap as $screenName => $replacement) {
            $needle = '@' . \ltrim($screenName, '@');
            $text = \str_ireplace($needle, $replacement, $text);
        }

        return $text;
    }
}
