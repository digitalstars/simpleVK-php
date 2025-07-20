<?php
declare(strict_types=1);

namespace DigitalStars\SimpleVK\EventDispatcher;

interface MiddlewareInterface
{
    /**
     * Обрабатывает входящее событие.
     *
     * @param Context $context Контекст текущего события.
     * @param callable $next Следующий обработчик в цепочке (другой middleware или финальный Action).
     * @return void
     */
    public function process(Context $context, callable $next): void;
}