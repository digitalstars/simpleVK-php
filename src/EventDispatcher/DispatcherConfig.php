<?php

namespace DigitalStars\SimpleVK\EventDispatcher;
use Closure;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;

class DispatcherConfig
{
    private ?Closure $factory = null;

    public function __construct(
        public readonly array $actionsPaths,
        public readonly string $rootNamespace,
        public readonly ?CacheInterface $cache = null
    ) {}

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
     */
    public function withContainer(ContainerInterface $container): self
    {
        $this->factory = static fn(string $class) => $container->get($class);
        return $this;
    }

    public function getFactory(): ?callable
    {
        return $this->factory;
    }
}