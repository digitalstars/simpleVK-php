<?php
namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\Message;

abstract class BaseView
{
    protected Context $ctx;

    public function setContext(Context $context): void
    {
        $this->ctx = $context;
    }

    abstract public function render(): Message;

    protected function msg(string $text = ''): Message
    {
        return $this->ctx->vk->msg($text);
    }
}