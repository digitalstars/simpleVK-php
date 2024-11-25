<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Internal\UniqueEventHandler;
use Psr\SimpleCache\CacheInterface;

class Setting {
    /**
     * Включает игнорирование дублирующихся событий от VK. По умолчанию для этого нужен Redis
     *
     * @param CacheInterface|null $cache Кастомный PSR-16 кэш (если null, используется встроенный Redis)
     * @param string $redis_host Хост Redis (используется, если $cache не указан)
     * @param int $redis_port Порт Redis (используется, если $cache не указан)
     * @param int $event_ttl Время жизни события в секундах
     * @return void
     */
    public static function enableUniqueEventHandler(
        ?CacheInterface $cache = null,
        string $redis_host = 'localhost',
        int $redis_port = 6379,
        int $event_ttl = 259200
    ): void {
        UniqueEventHandler::enable($cache, $redis_host, $redis_port, $event_ttl);
    }
}
