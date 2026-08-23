<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Dispatcher;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Message\OutgoingMessage;
use ReflectionClass;
use RuntimeException;

/**
 * Контекст обработки события: данные события, DI, запуск Actions/Views.
 */
class Context
{
    /** @var class-string|null Класс текущего обрабатываемого Action. */
    public ?string $actionClass = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly ApiClient $api,
        private readonly EventDispatcher $dispatcher,
        private readonly ArgumentResolver $argumentResolver,
        /**
         * Объект-обёртка исходного события: IncomingMessage или сырой массив callback/прочих событий.
         */
        public readonly object|array $event,
        public readonly ?int $userId = null,
        public readonly ?int $peerId = null,
        public readonly ?string $messageText = null,
        /**
         * Фабрика DI: class-string => объект. Closure, т.к. свойства не могут быть callable.
         */
        private readonly ?\Closure $factory = null,
    ) {}

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /**
     * Получает экземпляр класса из DI-контейнера/фабрики.
     *
     * @param class-string $className
     */
    public function get(string $className): object
    {
        if (\is_a($this, $className)) {
            return $this;
        }

        if (!\is_callable($this->factory)) {
            throw new RuntimeException('DI-фабрика не задана (DispatcherConfig::withContainer/withFactory)');
        }

        /** @var object $instance */
        return ($this->factory)($className);
    }

    /**
     * Создаёт и рендерит View (render() резолвится через DI).
     */
    public function getView(string $viewClass, mixed ...$args): OutgoingMessage
    {
        $instance = $this->dispatcher->createInstance($viewClass, $this);

        if (!\method_exists($instance, 'render') || !\class_exists($viewClass)) {
            throw new RuntimeException("Класс {$viewClass} должен иметь метод render()");
        }

        $reflectionMethod = new ReflectionClass($viewClass)->getMethod('render');
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $this, $args);

        return $instance->render(...$resolvedArgs);
    }

    /**
     * Рендерит и отправляет View текущему пользователю.
     *
     * @return list<int> ID отправленных сообщений.
     */
    public function sendView(string $viewClass, mixed ...$args): array
    {
        return $this->getView($viewClass, ...$args)->send();
    }

    /**
     * Запускает другой Action в рамках текущего запроса.
     *
     * @param class-string $actionClass
     * @param array<string, mixed> $args
     */
    public function run(string $actionClass, array $args = []): void
    {
        $this->dispatcher->runAction($actionClass, $this, $args);
    }

    /**
     * Быстрый доступ к конструктору исходящего сообщения текущего диалога.
     */
    public function msg(string $text = ''): OutgoingMessage
    {
        $message = new OutgoingMessage($this->api);

        if ($this->peerId !== null) {
            $message->to($this->peerId);
        } elseif ($this->userId !== null) {
            $message->toUser($this->userId);
        }

        return $message->text($text);
    }
}
