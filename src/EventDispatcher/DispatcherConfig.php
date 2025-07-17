<?php

namespace DigitalStars\SimpleVK\EventDispatcher;
use Closure;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;

class DispatcherConfig
{
    private ?Closure $factory = null;

    /**
     * @param array $actionsPaths Ассоциативный массив ['RootNamespace\\' => '/path/to/dir' или ['/path1', '/path2']].
     * @param bool $debug
     * @param CacheInterface|null $cache
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly array $actionsPaths,
        public readonly bool $debug = false,
        public readonly ?CacheInterface $cache = null
    ) {
        $this->validatePaths();
    }


    private function validatePaths(): void
    {
        if (empty($this->actionsPaths)) {
            throw new InvalidArgumentException(
                "Ошибка конфигурации диспетчера: массив путей (paths) не может быть пустым."
            );
        }

        foreach ($this->actionsPaths as $namespace => $pathOrPaths) {
            // Превращаем одиночный путь в массив для единообразной обработки
            $pathsToCheck = is_array($pathOrPaths) ? $pathOrPaths : [$pathOrPaths];

            if (empty($pathsToCheck)) {
                throw new InvalidArgumentException(
                    "Ошибка конфигурации диспетчера: для пространства имен '{$namespace}' указан пустой массив путей."
                );
            }

            foreach ($pathsToCheck as $path) {
                if (!is_string($path) || !is_dir($path)) {
                    throw new InvalidArgumentException(
                        "Ошибка конфигурации диспетчера: указанный путь '{$path}' для пространства имен '{$namespace}' не существует или не является директорией."
                    );
                }
            }
        }
    }

    /**
     * Задает пользовательскую фабрику для создания обработчиков событий.
     * @param callable $factory Логика для создания объекта.
     *        Может быть передана как анонимная функция, так и метод существующего объекта.
     * @return $this
     * @api
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
}