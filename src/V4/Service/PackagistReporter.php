<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Service;

/**
 * Отправка анонимной статистики установки на Packagist.
 *
 * Активируется ТОЛЬКО в CI-сборках, прикрепляющих архив с vendor к релизу
 * (для пользователей без Composer). Для обычных Composer-установок неактивен.
 *
 * Вызов: PackagistReporter::checkAndReport(); — безопасен для многократного вызова.
 */
final class PackagistReporter
{
    private const string VENDOR_PATH = __DIR__ . '/../../../../vendor';

    /**
     * @param non-empty-string $libraryVersion Версия библиотеки (SIMPLEVK_VERSION).
     */
    public static function checkAndReport(string $libraryVersion = '4.0.0'): void
    {
        $reportedFile = self::reportedVersionFile($libraryVersion);

        if (\file_exists($reportedFile)) {
            return;
        }

        try {
            self::reportComposer();
            @\touch($reportedFile);
        } catch (\Throwable) {
            // Отчётность никогда не влияет на работу бота.
        }
    }

    private static function reportedVersionFile(string $version): string
    {
        if (\is_writable(self::VENDOR_PATH)) {
            return self::VENDOR_PATH . '/.packagist_reported_' . $version;
        }

        $projectRootPath = \dirname(self::VENDOR_PATH) . '/';
        $projectHash = \md5($projectRootPath);

        return \sys_get_temp_dir() . '/simplevk_reporter_' . $projectHash . '_' . $version;
    }

    /**
     * Список пакетов vendor/composer/installed.json.
     *
     * @return array<string, string> name => version_normalized
     */
    private static function extractVersions(): array
    {
        $path = self::VENDOR_PATH . '/composer/installed.json';

        if (!\is_readable($path)) {
            return [];
        }

        try {
            $data = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $packages = [];
        foreach ($data['packages'] ?? [] as $package) {
            if (
                isset($package['name'], $package['version_normalized'])
                && \is_string($package['name'])
                && \is_string($package['version_normalized'])
            ) {
                $packages[$package['name']] = $package['version_normalized'];
            }
        }

        return $packages;
    }

    private static function reportComposer(): void
    {
        $packages = self::extractVersions();

        if ($packages === []) {
            return;
        }

        $downloads = [];
        foreach ($packages as $name => $version) {
            $downloads[] = ['name' => $name, 'version' => $version];
        }

        $phpVersion = 'PHP ' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION . '.' . \PHP_RELEASE_VERSION;
        $userAgent = \sprintf(
            'Composer/%s (%s; %s; %s; SimpleVK-CI-Installer)',
            '2.8.6',
            \function_exists('php_uname') ? \php_uname('s') : 'Unknown',
            \function_exists('php_uname') ? \php_uname('r') : 'Unknown',
            $phpVersion,
        );

        $context = \stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: {$userAgent}\r\n",
            'content' => \json_encode(['downloads' => $downloads]),
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);

        @\file_get_contents('https://packagist.org/downloads/', false, $context);
    }
}
