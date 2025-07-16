<?php

namespace DigitalStars\SimpleVK\EventDispatcher;
use Closure;
use InvalidArgumentException;
use JsonException;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;

class DispatcherConfig
{
    private ?Closure $factory = null;

    /**
     * @param array $actionsPaths Массив путей к директориям с обработчиками.
     * @param string $rootNamespace Корневое пространство имен для обработчиков (например, 'App\Actions').
     * @param CacheInterface|null $cache PSR-16 совместимый кэш.
     * @throws InvalidArgumentException Если один из путей в $actionsPaths не существует или не является директорией.
     */
    public function __construct(
        public readonly array $actionsPaths,
        public readonly string $rootNamespace,
        public readonly bool $debug = false,
        public readonly ?CacheInterface $cache = null
    ) {
        $this->validatePaths();
        $this->validateRootNamespaceSyntax();

        if ($this->debug) {
            $this->validateRootNamespaceAgainstComposer();
        }
    }

    /**
     * Проверяет, что все пути к обработчикам существуют и являются директориями.
     * @throws InvalidArgumentException
     */
    private function validatePaths(): void
    {
        if (empty($this->actionsPaths)) {
            throw new InvalidArgumentException(
                "Ошибка конфигурации диспетчера: массив путей к обработчикам (actionsPaths) не может быть пустым."
            );
        }

        foreach ($this->actionsPaths as $path) {
            if (!is_dir($path)) {
                throw new InvalidArgumentException(
                    "Ошибка конфигурации диспетчера: указанный путь '{$path}' не существует или не является директорией."
                );
            }
        }
    }

    private function validateRootNamespaceSyntax(): void
    {
        $namespaceToValidate = trim($this->rootNamespace, '\\/');

        if (empty($namespaceToValidate)) {
            throw new InvalidArgumentException(
                "Ошибка конфигурации диспетчера: 'rootNamespace' не может быть пустым. " .
                "Укажите корневое пространство имен (например, 'App')."
            );
        }

        $namespaceToValidate = str_replace('/', '\\', $namespaceToValidate);

        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $namespaceToValidate)) {
            throw new InvalidArgumentException(
                "Ошибка конфигурации диспетчера: '{$this->rootNamespace}' содержит недопустимые символы или неверную структуру"
            );
        }
    }

    private function validateRootNamespaceAgainstComposer(): void
    {
        $composerJsonPath = $this->findComposerJson();
        if (!$composerJsonPath) {
            trigger_error("Не удалось найти composer.json для проверки rootNamespace. Проверка пропущена.", E_USER_WARNING);
            return;
        }

        $composerConfig = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
        $psr4Namespaces = $composerConfig['autoload']['psr-4'] ?? [];

        if (empty($psr4Namespaces)) {
            return;
        }

        $normalizedInputNs = trim($this->rootNamespace, '\\');

        foreach (array_keys($psr4Namespaces) as $psr4Ns) {
            $normalizedPsr4Ns = trim($psr4Ns, '\\');
            if ($normalizedInputNs === $normalizedPsr4Ns || str_starts_with($normalizedInputNs, $normalizedPsr4Ns . '\\')) {
                return; // Нашли совпадение, все в порядке
            }
        }

        throw new InvalidArgumentException(
            "Ошибка конфигурации диспетчера: пространство имен '{$this->rootNamespace}' не найдено в секции 'autoload.psr-4' файла composer.json.\n" .
            "Зарегистрированные пространства имен: " . implode(', ', array_keys($psr4Namespaces))
        );
    }

    /**
     * Ищет путь к composer.json, двигаясь вверх от первой директории из actionsPaths.
     * @return string|null Путь к файлу или null, если не найден.
     */
    private function findComposerJson(): ?string
    {
        $dir = realpath($this->actionsPaths[0]);
        while ($dir && $dir !== DIRECTORY_SEPARATOR && $dir !== '') {
            $filePath = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($filePath)) {
                return $filePath;
            }
            $dir = dirname($dir);
        }
        return null;
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