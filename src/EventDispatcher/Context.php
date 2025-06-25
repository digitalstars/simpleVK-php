<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\Message;
use DigitalStars\SimpleVK\SimpleVK;
use Closure;
use DigitalStars\SimpleVK\SimpleVkException;
use ReflectionClass;
use RuntimeException;

class Context
{
    public function __construct(
        public readonly SimpleVK $vk,
        private readonly EventDispatcher $dispatcher,
        public readonly object $event,
        public readonly int $userId,
        public readonly int $peerId,
        private readonly ArgumentResolver $argumentResolver,
        public readonly ?string $messageText = null,
        /**
         * Фабрика для создания экземпляров классов, предоставленная пользователем.
         * @var callable|null
         */
        private readonly ?Closure $factory = null,
    ) {
    }

    /**
     * Получает экземпляр класса из DI-контейнера/фабрики.
     *
     * @template T
     * @param class-string<T> $className Имя класса для создания.
     * @return T
     * @throws RuntimeException Если фабрика не была предоставлена при инициализации.
     */
    public function get(string $className): object
    {
        if($className === __CLASS__) { //Context
            return $this;
        }

        if (!is_callable($this->factory)) {
            throw new RuntimeException(
                'The dependency resolver (factory/container) is not available in the current context.'
            );
        }

        return ($this->factory)($className);
    }

    /**
     * Создает и рендерит View, возвращая готовый к отправке объект Message.
     *
     * @param string $viewClass Класс View для рендеринга.
     * @param mixed ...$args Аргументы для конструктора View.
     * @return Message
     */
    public function getView(string $viewClass, ...$args): Message
    {
        $instance = null;
        $reflectionClass = new \ReflectionClass($viewClass);

        // Шаг 1: Попытка создать через пользовательскую фабрику/DI
        if (is_callable($this->factory)) {
            try {
                $instance = ($this->factory)($viewClass);
            } catch (\Throwable) {
                $instance = null;
            }
        }

        if (!$instance) {
            $constructorReflection = $reflectionClass->getConstructor();

            if ($constructorReflection) {
                $finalArgs = $this->argumentResolver->getArguments($constructorReflection, $this, $args);
                //в 2 раза быстрее, чем $reflectionClass->newInstanceArgs
                $instance = new $viewClass(...$finalArgs);
            } else {
                $instance = new $viewClass();
            }
        }

//        if (!$instance instanceof BaseView) {
//            throw new \LogicException("Класс '$viewClass' должен наследоваться от BaseView.");
//        }

        if (!method_exists($instance, 'render')) {
            throw new \RuntimeException("Класс {$viewClass} должен иметь метод render()");
        }

        $reflectionMethod = $reflectionClass->getMethod('render');
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $this);
        return $instance->render(...$resolvedArgs);
    }

    /**
     * Создает, рендерит и отправляет View текущему пользователю.
     *
     * @param string $viewClass Класс View для рендеринга.
     * @param mixed ...$args Аргументы для конструктора View.
     * @return mixed Результат выполнения запроса отправки (например, message_id).
     * @throws SimpleVkException
     */
    public function sendView(string $viewClass, ...$args): mixed
    {
        return $this->getView($viewClass, ...$args)->send();
    }

    /**
     * Запускает другой Action в рамках текущего запроса.
     * @param class-string $actionClass Класс Action для запуска.
     * @param array $args Дополнительные аргументы (payload/regex) для нового Action.
     */
    public function run(string $actionClass, array $args = []): void
    {
        // Делегируем запуск диспетчеру, который знает весь жизненный цикл
        $this->dispatcher->executeAction(
            $actionClass,
            $this->userId,
            $this->peerId,
            $args,
            (array)$this->event
        );
    }

    public function msg(string $text = ''): Message
    {
        return $this->vk->msg($text);
    }
}