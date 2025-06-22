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
    /** @var string Среда веб-сервера (Apache, Nginx, FPM, Caddy, FrankenPHP). */
    public const ENV_WEB = 'web';

    /** @var string Интерактивная командная строка. */
    public const ENV_CLI_INTERACTIVE = 'cli_interactive';

    /** @var string Неинтерактивная командная строка (cron, демон, вывод в файл, embed без веб-контекста). */
    public const ENV_CLI_NON_INTERACTIVE = 'cli_non_interactive';

    /**
     * @var string|null Кэшированный результат определения среды.
     */
    private static ?string $detectedEnvironment = null;

    /**
     * Предотвращение создание экземпляра класса, потому что только статическое использование.
     */
    private function __construct()
    {
    }

    /**
     * Определяет и возвращает текущую среду выполнения.
     *
     * @return string Одна из констант: self::ENV_WEB, self::ENV_CLI_INTERACTIVE, self::ENV_CLI_NON_INTERACTIVE.
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

    /**
     * Является ли текущая среда веб-окружением.
     * @return bool
     */
    public static function isWeb(): bool
    {
        return self::getEnvironment() === self::ENV_WEB;
    }

    /**
     * Является ли текущая среда консолью (любого типа).
     * @return bool
     */
    public static function isCli(): bool
    {
        $env = self::getEnvironment();
        return $env === self::ENV_CLI_INTERACTIVE || $env === self::ENV_CLI_NON_INTERACTIVE;
    }

    /**
     * Является ли текущая среда интерактивной консолью.
     * @return bool
     */
    public static function isInteractiveCli(): bool
    {
        return self::getEnvironment() === self::ENV_CLI_INTERACTIVE;
    }
}