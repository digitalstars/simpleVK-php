<?php

namespace DigitalStars\SimpleVK\EventDispatcher;
use Closure;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;

class DispatcherConfig
{
    private ?Closure $factory = null;
    public readonly array $actionsPaths;
    private array $middleware = []; // <-- Добавить свойство

    /**
     * @param array|string $actionsPaths Массив путей к директориям с Action-классами.
     * @param bool $debug
     * @param CacheInterface|null $cache
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
     * @param callable $factory Логика для создания объекта.
     *        Может быть передана как анонимная функция, так и метод существующего объекта.
     * @return $this
     */
    public function withFactory(callable $factory): self
    {
        $this->factory = $factory(...);
        return $this;
    }

    /**
     * Задает PSR-11 DI-контейнер для создания обработчиков.
     * @param ContainerInterface $container PSR-11 совместимый контейнер.
     * @return $this
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
     * @param array<class-string<MiddlewareInterface>> $middlewareStack Массив классов middleware.
     * @return $this
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