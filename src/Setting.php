<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Internal\UniqueEventHandler;

class Setting {
    /**
     * Включает игнорирование дублирующихся событий от VK
     * @param string $redis_host
     * @param int $redis_port
     * @param int $event_ttl
     * @return void
     */
    public static function enableUniqueEventHandler(string $redis_host = 'localhost', int $redis_port = 6379, int $event_ttl = 259200)
    {
        UniqueEventHandler::enable($redis_host, $redis_port, $event_ttl);
    }
}