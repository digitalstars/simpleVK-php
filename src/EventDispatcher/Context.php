<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use Closure;
use DigitalStars\SimpleVK\Message;
use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\SimpleVkException;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Контекст обработки события: данные события, DI, запуск Actions/Views.
 */
class Context
{
    /** @var class-string|null Класс текущего обрабатываемого Action */
    public ?string $actionClass = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly SimpleVK $vk,
        private readonly EventDispatcher $dispatcher,
        private readonly ArgumentResolver $argumentResolver,
        /** @var object Объект-обёртка над исходным событием VK. */
        public readonly object $event,
        public readonly ?int $userId,
        public readonly ?int $peerId,
        public readonly ?string $messageText = null,
        /**
         * Фабрика для создания экземпляров классов, предоставленная пользователем.
         */
        private readonly ?Closure $factory = null,
    ) {
    }

    /**
     * Возвращает значение атрибута.
     *
     * @template T
     * @param string $name Имя атрибута.
     * @param T|null $default Значение по умолчанию.
     * @return T|null
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Устанавливает значение атрибута.
     */
    public function setAttribute(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;
        return $this;
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
        if ($className === __CLASS__) {
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
     * @throws ReflectionException
     */
    public function getView(string $viewClass, mixed ...$args): Message
    {
        $instance = $this->dispatcher->createInstance($viewClass, $this);

        if (!method_exists($instance, 'render')) {
            throw new RuntimeException("Класс {$viewClass} должен иметь метод render()");
        }

        $reflectionMethod = (new ReflectionClass($viewClass))->getMethod('render');

        // Разрешаем аргументы для метода render, передавая ему $args.
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $this, $args);

        return $instance->render(...$resolvedArgs);
    }

    /**
     * Создает, рендерит и отправляет View текущему пользователю.
     *
     * @return mixed Результат выполнения запроса отправки (например, message_id).
     * @throws SimpleVkException
     */
    public function sendView(string $viewClass, mixed ...$args): mixed
    {
        return $this->getView($viewClass, ...$args)->send();
    }

    /**
     * Запускает другой Action в рамках текущего запроса.
     *
     * @param class-string $actionClass Класс Action для запуска.
     * @param array $args Дополнительные аргументы (payload/regex) для нового Action.
     */
    public function run(string $actionClass, array $args = []): void
    {
        // Делегируем запуск диспетчеру, который знает весь жизненный цикл
        $this->dispatcher->runAction($actionClass, $this, $args);
    }

    /**
     * Быстрый доступ к конструктору сообщений.
     */
    public function msg(string $text = ''): Message
    {
        return $this->vk->msg($text);
    }

    /**
     * Быстрый ответ на событие текстом.
     */
    public function reply(string $text = ''): ?int
    {
        return $this->vk->reply($text);
    }
}
