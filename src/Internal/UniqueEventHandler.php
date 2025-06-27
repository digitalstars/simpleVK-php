<?php

namespace DigitalStars\SimpleVK\Internal;

use Exception;
use LogicException;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Psr\SimpleCache\CacheInterface;
use Redis;

class UniqueEventHandler {
    private static ?CacheInterface $cache = null;
    private static int $cache_ttl; // 3 дня
    private static bool $is_enabled = false;

    /**
     * Включение обработки уникальных событий
     *
     * @param CacheInterface|null $cache PSR-16 кэш
     * @param string $redis_host Хост Redis (используется, если $cache не указан)
     * @param int $redis_port Порт Redis (используется, если $cache не указан)
     * @param int $cache_ttl Время жизни записи в кэше
     * @return void
     */
    public static function enable(
        ?CacheInterface $cache = null,
        string $redis_host = 'localhost',
        int $redis_port = 6379,
        int $cache_ttl = 259200
    ): void {
        self::$cache_ttl = $cache_ttl;

        if ($cache !== null) {
            self::$cache = $cache;
        } else {
            self::$cache = self::createDefaultRedisCache($redis_host, $redis_port);
        }

        self::$is_enabled = true;
    }

    /**
     * Проверяет, является ли событие дубликатом.
     * Если событие новое, оно регистрируется в кэше.
     *
     * @param array $event Событие от VK
     * @return bool True, если событие уже было обработано, иначе False.
     * @throws InvalidArgumentException
     */
    public static function addEventToCache(array $event): bool
    {
        if (!self::$is_enabled || self::$cache === null) {
            return false;
        }

        $peer_id = $event['object']['message']['peer_id'] ?? null;
        $cmid = $event['object']['message']['conversation_message_id'] ?? null;

        if ($peer_id === null || $cmid === null) {
            return false;
        }

        // Symfony Cache автоматически добавит к нему префикс
        $key = "event_{$peer_id}_{$cmid}";

        if (self::$cache->has($key)) {
            return true; // дубликат
        }

        self::$cache->set($key, true, self::$cache_ttl);
        return false;
    }

    private static function createDefaultRedisCache(string $host, int $port): CacheInterface
    {
        if (!class_exists(Redis::class) || !extension_loaded('redis')) {
            throw new LogicException("Для работы кэша по умолчанию необходимо расширение ext-redis.");
        }

        try {
            $redisClient = new Redis();
            $redisClient->connect($host, $port);
            // PSR-6 адаптер, svk:unique_event: - префикс
            $psr6Cache = new RedisAdapter($redisClient, 'svk.unique_event.');

            // Оборачиваем его в PSR-16 совместимый кеш
            return new Psr16Cache($psr6Cache);
        } catch (Exception $e) {
            throw new RuntimeException("Не удалось подключиться к Redis на {$host}:{$port}\n".$e->getMessage(), 0, $e);
        }
    }
}
