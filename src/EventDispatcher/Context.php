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
        private readonly ArgumentResolver $argumentResolver,
        public readonly object $event,
        public readonly ?int $userId,
        public readonly ?int $peerId,
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
     * @throws \ReflectionException
     */
    public function getView(string $viewClass, ...$args): Message
    {
        $instance = $this->dispatcher->createInstance($viewClass, $this);

        if (!method_exists($instance, 'render')) {
            throw new \RuntimeException("Класс {$viewClass} должен иметь метод render()");
        }

        $reflectionMethod = (new ReflectionClass($viewClass))->getMethod('render');

        // Шаг 2: Разрешаем аргументы для метода render, передавая ему $args.
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $this, $args);

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
        $this->dispatcher->runAction($actionClass, $this, $args);
    }

    public function msg(string $text = ''): Message
    {
        return $this->vk->msg($text);
    }

    public function reply(string $text = '')
    {
        return $this->vk->reply($text);
    }
}