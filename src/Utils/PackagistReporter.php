<?php
/**
 * PackagistReporter: Класс для отправки анонимной статистики установки на Packagist.
 *
 * ВАЖНО: Этот класс активируется ТОЛЬКО в CI-сборках,
 * которые крепят к релизу архив с папкой vendor.
 * Для обычных пользователей Composer
 * этот класс неактивен и не несет никакой нагрузки.
 */
namespace DigitalStars\SimpleVK\Utils;

use const DigitalStars\SimpleVK\SIMPLEVK_VERSION;

require_once __DIR__ . '/../config_simplevk.php';

final class PackagistReporter
{
    private const LIBRARY_VERSION = SIMPLEVK_VERSION;

    private const VENDOR_PATH = __DIR__ . '/../../../../../vendor';

    /**
     * Проверяет, нужно ли отправлять отчет для текущей версии, и если да,
     * то запускает весь процесс. Безопасен для многократного вызова.
     */
    public static function checkAndReport(): void
    {
        $reportedVersionFile = self::getReportedVersionFile();

        if (file_exists($reportedVersionFile)) {
            return;
        }

        try {
            self::reportComposer();
            @touch($reportedVersionFile);
        } catch (\Throwable $e) {}
    }

    /**
     * Определяет путь к файлу-метке, используя vendor или временную папку.
     * @return string Абсолютный путь к файлу-метке.
     */
    private static function getReportedVersionFile(): string
    {
        if (is_writable(self::VENDOR_PATH)) {
            return self::VENDOR_PATH . '/.packagist_reported_' . self::LIBRARY_VERSION;
        }

        $projectRootPath = dirname(self::VENDOR_PATH) . '/';
        $projectHash = md5((string)$projectRootPath);

        return sys_get_temp_dir() . '/simplevk_reporter_' . $projectHash . '_' . self::LIBRARY_VERSION;
    }

    /**
     * Извлекает список всех установленных пакетов из `vendor/composer/installed.json`.
     * @return array<string, string>
     */
    private static function extractVersions(): array
    {
        $installedJsonPath = self::VENDOR_PATH . '/composer/installed.json';

        if (!is_readable($installedJsonPath)) {
            return [];
        }

        try {
            $composerData = json_decode((string)file_get_contents($installedJsonPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }

        $packages = [];

        if (empty($composerData['packages'])) {
            return [];
        }

        foreach ($composerData['packages'] as $package) {
            if (!empty($package['name']) && !empty($package['version_normalized'])) {
                $packages[$package['name']] = $package['version_normalized'];
            }
        }
        return $packages;
    }

    /**
     * Формирует и отправляет POST-запрос на эндпоинт статистики Packagist.
     */
    private static function reportComposer(): void
    {
        $packages = self::extractVersions();
        if (empty($packages)) {
            return;
        }

        $downloads = [];
        foreach ($packages as $name => $version) {
            $downloads[] = ['name' => $name, 'version' => $version];
        }
        $postData = ['downloads' => $downloads];

        $phpVersion = 'PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
        $userAgent = sprintf(
            'Composer/%s (%s; %s; %s; SimpleVK-CI-Installer)', //прозрачно говорит о том, что у нас свой установщик
            '2.8.6', // Актуальная версия Composer
            function_exists('php_uname') ? php_uname('s') : 'Unknown',
            function_exists('php_uname') ? php_uname('r') : 'Unknown',
            $phpVersion
        );

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                    "User-Agent: {$userAgent}\r\n",
                'content' => json_encode($postData),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ];

        @file_get_contents('https://packagist.org/downloads/', false, stream_context_create($opts));
    }
}