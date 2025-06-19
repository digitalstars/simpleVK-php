<?php
namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\Message;
use DigitalStars\SimpleVK\SimpleVkException;

abstract class BaseAction implements ActionInterface
{
    protected Context $ctx;

    public function setContext(Context $context): void
    {
        $this->ctx = $context;
    }

    public function before(Context $ctx): bool
    {
        return true;
    }

    abstract public function handle(Context $ctx, ...$args): void;

    /**
     * Создает и рендерит View, возвращая готовый к отправке объект Message.
     * Этот метод НЕ отправляет сообщение.
     *
     * @param string $viewClass Класс View для рендеринга.
     * @param mixed ...$args Аргументы для конструктора View.
     * @return Message
     */
    protected function getView(string $viewClass, ...$args): Message
    {
        $view = new $viewClass(...$args);

        if (method_exists($view, 'setContext')) {
            $view->setContext($this->ctx);
        }

        return $view->render();
    }

    /**
     * Создает, рендерит и отправляет View текущему пользователю.
     *
     * @param string $viewClass Класс View для рендеринга.
     * @param mixed ...$args Аргументы для конструктора View.
     * @return mixed Результат выполнения запроса отправки (например, message_id).
     * @throws SimpleVkException
     */
    protected function sendView(string $viewClass, ...$args)
    {
        return $this->getView($viewClass, ...$args)->send();
    }
}