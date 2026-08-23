<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Конфигурация EventDispatcher: пути к Actions, DI-фабрика, middleware, отладка.
 */
class DispatcherConfig
{
    private ?Closure $factory = null;
    /** @var array<string> */
    public readonly array $actionsPaths;
    /** @var array<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /**
     * @param array<string>|string $actionsPaths Массив путей к директориям с Action-классами.
     */
    public function __construct(
        array|string $actionsPaths,
        public readonly bool $debug = false,
        public readonly ?CacheInterface $cache = null
    ) {
        $this->actionsPaths = is_string($actionsPaths) ? [$actionsPaths] : $actionsPaths;
        $this->validatePaths();
    }


    private function validatePaths(): void
    {
        if (empty($this->actionsPaths)) {
            throw new InvalidArgumentException(
                "Ошибка конфигурации диспетчера: массив путей (actionsPaths) не может быть пустым."
            );
        }

        foreach ($this->actionsPaths as $path) {
            if (!is_string($path) || !is_dir($path)) {
                throw new InvalidArgumentException(
                    "Ошибка конфигурации диспетчера: указанный путь '{$path}' не существует или не является директорией."
                );
            }
        }
    }

    /**
     * Задает пользовательскую фабрику для создания обработчиков событий.
     *
     * @param callable $factory Логика для создания объекта: fn(string $class): object.
     */
    public function withFactory(callable $factory): self
    {
        $this->factory = $factory(...);
        return $this;
    }

    /**
     * Задает PSR-11 DI-контейнер для создания обработчиков.
     *
     * @api
     */
    public function withContainer(ContainerInterface $container): self
    {
        $this->factory = static fn(string $class) => $container->get($class);
        return $this;
    }

    /**
     * @return callable|null
     * @api
     */
    public function getFactory(): ?callable
    {
        return $this->factory;
    }

    /**
     * Задает глобальные middleware, которые будут применены ко всем экшенам.
     *
     * @param array<class-string<MiddlewareInterface>> $middlewareStack Массив классов middleware.
     */
    public function withMiddleware(array $middlewareStack): self
    {
        $this->middleware = $middlewareStack;
        return $this;
    }

    /**
     * @return array<class-string<MiddlewareInterface>>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
