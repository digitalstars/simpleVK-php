<?php

namespace DigitalStars\SimpleVK\Utils;

/**
 * Класс-хелпер для определения среды выполнения PHP.
 *
 * Предоставляет надежный способ различать веб-окружение (включая FrankenPHP),
 * интерактивную консоль (CLI) и неинтерактивные консольные задачи (cron, демон, embed).
 */
final class EnvironmentDetector
{
    /** Среда веб-сервера (Apache, Nginx, FPM, Caddy, FrankenPHP). */
    public const ENV_WEB = 'web';

    /** Интерактивная командная строка. */
    public const ENV_CLI_INTERACTIVE = 'cli_interactive';

    /** Неинтерактивная командная строка (cron, демон, вывод в файл, embed без веб-контекста). */
    public const ENV_CLI_NON_INTERACTIVE = 'cli_non_interactive';

    private static ?string $detectedEnvironment = null;

    /** Только статическое использование. */
    private function __construct()
    {
    }

    /**
     * Определяет и возвращает текущую среду выполнения.
     */
    public static function getEnvironment(): string
    {
        if (self::$detectedEnvironment !== null) {
            return self::$detectedEnvironment;
        }

        // Это веб-контекст Apache, и Nginx, и FrankenPHP
        if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['SERVER_PROTOCOL']) || PHP_SAPI === 'cli-server') {
            return self::$detectedEnvironment = self::ENV_WEB;
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            // Проверяем, интерактивная ли консоль
            if (defined('STDOUT') && stream_isatty(STDOUT)) {
                return self::$detectedEnvironment = self::ENV_CLI_INTERACTIVE;
            }
        }

        // Сюда попадет cron, демон, вывод в файл, embed без веб-контекста ...
        return self::$detectedEnvironment = self::ENV_CLI_NON_INTERACTIVE;
    }

    public static function isWeb(): bool
    {
        return self::getEnvironment() === self::ENV_WEB;
    }

    public static function isCli(): bool
    {
        $env = self::getEnvironment();
        return $env === self::ENV_CLI_INTERACTIVE || $env === self::ENV_CLI_NON_INTERACTIVE;
    }

    public static function isInteractiveCli(): bool
    {
        return self::getEnvironment() === self::ENV_CLI_INTERACTIVE;
    }
}
