<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dispatcher;

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
