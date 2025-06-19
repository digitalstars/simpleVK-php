<?php
namespace DigitalStars\SimpleVK\EventDispatcher;

interface ActionInterface
{
    public function handle(Context $ctx, ...$args): void;
    public function before(Context $ctx): bool;
    public function setContext(Context $context): void;
}