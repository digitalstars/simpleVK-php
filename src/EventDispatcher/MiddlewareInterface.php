<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

/**
 * Middleware-звено пайплайна обработки события.
 */
interface MiddlewareInterface
{
    /**
     * Обрабатывает входящее событие.
     *
     * @param Context $context Контекст текущего события.
     * @param callable $next Следующий обработчик в цепочке (другой middleware или финальный Action).
     */
    public function process(Context $context, callable $next): void;
}
