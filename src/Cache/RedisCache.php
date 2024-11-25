<?php

namespace DigitalStars\SimpleVK\Cache;

use DigitalStars\SimpleVK\Psr\SimpleCache\{CacheInterface, InvalidArgumentException};
use Redis;

class RedisCache implements CacheInterface {
    private Redis $redis;

    public function __construct(Redis $redis) {
        $redis->ping();// Check connection
        $this->redis = $redis;
    }

    public function get(string $key, mixed $default = null): mixed {
        $value = $this->redis->get($key);
        return $value !== false ? unserialize($value, ['allowed_classes' => true]) : $default;
    }

    public function set(string $key, mixed $value, int|null|\DateInterval $ttl = null): bool {
        $serialized = serialize($value);
        if ($ttl !== null) {
            return $this->redis->set($key, $serialized, ['ex' => $ttl]);
        }
        return $this->redis->set($key, $serialized);
    }

    public function delete(string $key): bool {
        return $this->redis->del($key) > 0;
    }

    public function clear(): bool {
        return $this->redis->flushDB();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, int|null|\DateInterval $ttl = null): bool {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool {
        return $this->redis->exists($key) > 0;
    }
}
