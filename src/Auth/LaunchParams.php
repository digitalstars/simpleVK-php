<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Auth;

use SensitiveParameter;

/**
 * Проверка подписи параметров запуска VK Mini Apps (launch params).
 *
 * Алгоритм по документации VK (dev.vk.com/ru/mini-apps/development/launch-params-sign):
 * vk_*-параметры сортируются по ключу, склеиваются через http_build_query,
 * подпись — HMAC-SHA256 защищённым ключом приложения, base64url без padding.
 *
 * Пример:
 *   $params = LaunchParams::parse($_SERVER['QUERY_STRING'] ?? '');
 *   if ($params !== null && LaunchParams::verify($params, $appSecret)) {
 *       $userId = (int) $params['vk_user_id'];
 *   }
 */
final class LaunchParams
{
    private const string SIGN_PARAM = 'sign';
    private const string PREFIX = 'vk_';

    /**
     * Разбирает параметры запуска из query-строки или уже разобранного массива.
     *
     * @param array<string, mixed>|string $launchParams QUERY_STRING, полный URL или массив параметров.
     *
     * @return array<string, string>|null null, если нет ни sign, ни vk_*-параметров.
     */
    public static function parse(array|string $launchParams): ?array
    {
        $params = \is_array($launchParams) ? $launchParams : self::fromString($launchParams);

        $sign = $params[self::SIGN_PARAM] ?? null;
        $hasVkParams = false;
        foreach ($params as $name => $_) {
            if (\str_starts_with($name, self::PREFIX)) {
                $hasVkParams = true;
                break;
            }
        }

        if (!\is_string($sign) || $sign === '' || !$hasVkParams) {
            return null;
        }

        /** @var array<string, string> */
        return \array_filter($params, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * Проверяет подпись параметров. Строковые значения сравниваются constant-time.
     *
     * @param array<string, mixed>|string $launchParams См. parse().
     * @param string $secret Защищённый ключ приложения.
     */
    public static function verify(array|string $launchParams, #[SensitiveParameter] string $secret): bool
    {
        $params = self::parse($launchParams);
        if ($params === null || $secret === '') {
            return false;
        }

        $expected = self::computeSign($params, $secret);

        return \hash_equals($expected, $params[self::SIGN_PARAM]);
    }

    /**
     * Вычисляет подпись для набора vk_*-параметров.
     *
     * @param array<string, string> $params Полный набор параметров (включая sign).
     */
    public static function computeSign(array $params, #[SensitiveParameter] string $secret): string
    {
        unset($params[self::SIGN_PARAM]);

        $signParams = [];
        foreach ($params as $name => $value) {
            if (\str_starts_with($name, self::PREFIX)) {
                $signParams[$name] = $value;
            }
        }

        \ksort($signParams);

        // Значения должны остаться в URL-кодировке: http_build_query даёт ровно это.
        $query = \http_build_query($signParams);

        return \rtrim(\strtr(\base64_encode(\hash_hmac('sha256', $query, $secret, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private static function fromString(string $launchParams): array
    {
        // Допустим полный URL — берём только query.
        $query = $launchParams;
        $pos = \strpos($launchParams, '?');
        if ($pos !== false && \str_starts_with($launchParams, 'http')) {
            $query = \substr($launchParams, $pos + 1);
        } elseif ($pos === 0) {
            $query = \substr($launchParams, 1);
        }

        $parsed = [];
        \parse_str($query, $parsed);

        /** @var array<string, string> */
        return \array_filter($parsed, \is_scalar(...));
    }
}
