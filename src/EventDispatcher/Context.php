<?php
namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\Store;

class Context
{
    public function __construct(
        public readonly SimpleVK $vk,
        public readonly EventDispatcher $dispatcher,
        public readonly Store $userStore,
        public readonly object $event,
        public readonly int $userId,
        public readonly int $peerId,
        public readonly ?string $messageText = null,
        public readonly ?array $payload = null
    ) {}
}