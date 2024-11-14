<?php

namespace DigitalStars\SimpleVK\Internal;

use Redis;

class UniqueEventHandler {
    private static ?Redis $redis = null;
    private static int $cache_ttl = 259200; // 3 дня
    private static bool $is_enabled = false;

    public static function enable(string $redis_host = 'localhost', int $redis_port = 6379, int $event_ttl = 259200): void {
        self::$cache_ttl = $event_ttl;
        self::initializeRedis($redis_host, $redis_port);
    }

    public static function addEventToCache(string $event_id): bool {
        if (!self::$is_enabled) {
            return false;
        }

        if (self::isEventProcessed($event_id)) {
            return true; // дубликат
        }

        self::$redis->set("svk_event_$event_id", true, self::$cache_ttl);
        return false;
    }

    private static function isEventProcessed(string $event_id): bool {
        return (bool) self::$redis->exists("svk_event_$event_id");
    }

    private static function initializeRedis(string $redis_host, int $redis_port): void {
        if (!self::$redis && class_exists(Redis::class) && extension_loaded('redis')) {
            self::$redis = new Redis();
            try {
                self::$redis->connect($redis_host, $redis_port);
                self::$is_enabled = true;
            } catch (\Exception $exception) {
                trigger_error("Redis недоступен: " . $exception->getMessage(), E_USER_ERROR);
            }
        } else {
            trigger_error("ext-redis не установлен или не включен в php.ini", E_USER_ERROR);
        }
    }
}