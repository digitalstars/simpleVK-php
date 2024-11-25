<?php

namespace DigitalStars\SimpleVK\Internal;

use DigitalStars\SimpleVK\Cache\RedisCache;
use DigitalStars\SimpleVK\Psr\SimpleCache\CacheInterface;
use Redis;
use Exception;

class UniqueEventHandler {
    private static ?CacheInterface $cache = null;
    private static int $cache_ttl = 259200; // 3 дня
    private static bool $is_enabled = false;

    /**
     * Включение обработки уникальных событий
     *
     * @param CacheInterface|null $cache Кастомный PSR-16 кэш
     * @param string $redis_host Хост Redis (используется, если $cache не указан)
     * @param int $redis_port Порт Redis (используется, если $cache не указан)
     * @param int $event_ttl Время жизни записи в кэше
     * @return void
     */
    public static function enable(
        ?CacheInterface $cache = null,
        string $redis_host = 'localhost',
        int $redis_port = 6379,
        int $event_ttl = 259200
    ): void {
        self::$cache_ttl = $event_ttl;

        if ($cache !== null) {
            self::$cache = $cache;
        } else {
            self::$cache = self::initializeRedisCache($redis_host, $redis_port);
        }

        self::$is_enabled = true;
    }

    /**
     * Добавляет событие в кэш
     *
     * @param string $event_id
     * @return bool True, если событие уже было обработано, иначе False
     */
    public static function addEventToCache(string $event_id): bool {
        if (!self::$is_enabled || self::$cache === null) {
            return false;
        }

        $key = "svk_event_$event_id";

        if (self::$cache->has($key)) {
            return true; // дубликат
        }

        self::$cache->set($key, true, self::$cache_ttl);
        return false;
    }

    private static function initializeRedisCache(string $redis_host, int $redis_port): RedisCache {
        if (class_exists(Redis::class) && extension_loaded('redis')) {
            try {
                $redis = new Redis();
                $redis->connect($redis_host, $redis_port);
                return new RedisCache($redis);
            } catch (Exception $exception) {
                trigger_error("Redis недоступен: " . $exception->getMessage(), E_USER_ERROR);
            }
        } else {
            trigger_error("ext-redis не установлен или не включен в php.ini", E_USER_ERROR);
        }
    }
}
