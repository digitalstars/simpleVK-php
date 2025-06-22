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
     * @param array $event
     * @return bool True, если событие уже было обработано, иначе False
     */
    public static function addEventToCache(array $event): bool {
        if (!self::$is_enabled || self::$cache === null) {
            return false;
        }

        $peer_id = $event['object']['message']['peer_id'] ?? null;
        $cmid = $event['object']['message']['conversation_message_id'] ?? null;

        if ($peer_id === null || $cmid === null) {
            return false;
        }

        $key = "svk_event_{$peer_id}_{$cmid}";

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
                throw new \RuntimeException("Redis недоступен: " . $exception->getMessage(), $exception->getCode(), $exception);
            }
        } else {
            throw new \LogicException("Расширение ext-redis не установлено или не включено в php.ini");
        }
    }
}
