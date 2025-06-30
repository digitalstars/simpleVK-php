<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\Attributes\{AsButton, Trigger, Fallback};
use LogicException;
use ReflectionClass;
use RecursiveIteratorIterator, RecursiveDirectoryIterator;
use ReflectionException;

class EventDispatcher
{
    private SimpleVK $vk;
    private DispatcherConfig $config;
    private array $routeMap = [
        'payload' => [],
        'command' => [],
        'regex' => [],
    ];
    private ?string $fallbackAction = null;
    private bool $isRoutesLoaded = false;
    private array $actionPaths = [];
    private ?\Closure $factory;
    private ArgumentResolver $argumentResolver;

    public function __construct(SimpleVK $vk, DispatcherConfig $config)
    {
        $this->vk = $vk;
        $this->config = $config;
        if (isset($config->actionsPaths)) {
            $this->registerActionPaths($config->actionsPaths);
        }
        $this->factory = $config->getFactory();
        $this->argumentResolver = new ArgumentResolver($config->cache ?? null);
    }

    public function registerActionPaths(array $paths): self
    {
        $this->actionPaths = array_merge($this->actionPaths, $paths);
        return $this;
    }

    public function handle(?array $externalEvent = null): void
    {
        if (!$this->isRoutesLoaded) {
            $this->loadRoutes();
        }

        $event = $externalEvent ?? $this->vk->data;
        $this->vk->data = $event;
        $this->vk->initText($text)->initUserID($userId)->initPayload($payload);

        if (is_null($userId)) {
            return;
        }

        $route = $this->findRoute($text, $payload);
        if (!$route) {
            return;
        }

        $context = $this->createContextFromEvent($event);
        $this->runAction($route['actionClass'], $context, $route['actionArgs']);
    }

    /**
     * Находит подходящий Action на основе текста и payload.
     *
     * @param string|null $text
     * @param array|null $payload
     * @return array|null Возвращает массив ['actionClass' => ..., 'actionArgs' => ...] или null, если ничего не найдено.
     */
    private function findRoute(?string $text, ?array $payload): ?array
    {
        // Приоритет 1: Кнопки (payload)
        if (!empty($payload['action'])) {
            $actionName = $payload['action'];
            if (isset($this->routeMap['payload'][$actionName])) {
                return [
                    'actionClass' => $this->routeMap['payload'][$actionName],
                    'actionArgs' => array_diff_key($payload, ['action' => ''])
                ];
            }
        }

        // Приоритет 2: Текстовые команды
        if (!empty($text)) {
            // Точное совпадение
            if (isset($this->routeMap['command'][$text])) {
                return [
                    'actionClass' => $this->routeMap['command'][$text],
                    'actionArgs' => []
                ];
            }
            // Поиск по регулярному выражению
            foreach ($this->routeMap['regex'] as $pattern => $actionClass) {
                if (preg_match($pattern, $text, $matches)) {
                    return [
                        'actionClass' => $actionClass,
                        'actionArgs' => array_slice($matches, 1)
                    ];
                }
            }
        }

        // Приоритет 3: Fallback Action
        if ($this->fallbackAction) {
            return [
                'actionClass' => $this->fallbackAction,
                'actionArgs' => []
            ];
        }

        return null;
    }

    public function createContextFromEvent(array $event): Context
    {
        $this->vk->data = $event;
        $this->vk->initText($text)->initUserID($userId)->initPeerID($peerId)->initData($rawEvent);
//        var_dump($text, $userId, $peerId, $rawEvent);
        return new Context($this->vk, $this, $this->argumentResolver, (object)$rawEvent, $userId, $peerId, $text, $this->factory);
    }

    /**
     * @throws ReflectionException
     */
    public function runAction(string $actionClass, Context $context, array $actionArgs = []): void
    {
        $instance = $this->createInstance($actionClass, $context);
        $reflectionClass = new ReflectionClass($actionClass); // нужен для getMethod

        if (method_exists($instance, 'before')) {
            $reflectionMethod = $reflectionClass->getMethod('before');
            $beforeArgs = $this->argumentResolver->getArguments($reflectionMethod, $context, $actionArgs);
            if ($instance->before(...$beforeArgs) === false) {
                return;
            }
        }

        if (!method_exists($instance, 'handle')) {
            throw new \RuntimeException("Класс {$actionClass} должен иметь метод handle()");
        }

        $reflectionMethod = $reflectionClass->getMethod('handle');
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $context, $actionArgs);
        $instance->handle(...$resolvedArgs);
    }

    /**
     * Создает экземпляр класса, используя фабрику/DI (через Context) и рефлексию.
     * Это универсальный метод для создания Action'ов и View'ов.
     *
     * @template T
     * @param class-string<T> $className
     * @param Context $context
     * @return T
     * @throws ReflectionException
     */
    public function createInstance(string $className, Context $context): object
    {
        $instance = null;

        // Приоритет 1: Попытка создать через пользовательскую фабрику/DI.
        if (is_callable($this->factory)) {
            try {
                $instance = $context->get($className);
            } catch (\Throwable) {}
        }

        // Приоритет 2: Ручное создание, если фабрика не справилась или ее нет.
        if (!$instance) {
            $reflectionClass = new ReflectionClass($className);
            $constructor = $reflectionClass->getConstructor();
            if ($constructor) {
                // Рантайм-аргументы сюда НЕ передаются.
                $constructorArgs = $this->argumentResolver->getArguments($constructor, $context);
                $instance = $reflectionClass->newInstanceArgs($constructorArgs);
            } else {
                $instance = $reflectionClass->newInstance();
            }
        }
        return $instance;
    }

    private function loadRoutes(): void
    {
        if ($this->isRoutesLoaded) {
            return;
        }
        foreach ($this->actionPaths as $path) {
            $this->scanDirectoryForActions($path);
        }
        $this->isRoutesLoaded = true;
    }

    private function scanDirectoryForActions(string $path): void
    {
        $realPath = realpath($path);
        if (!$realPath) {
            return;
        }

        //todo а нужен ли autoloader_root?
        $autoloaderRoot = $this->config->autoloader_root ?? dirname($realPath);
        $rootNamespace = $this->config->rootNamespace ?? '';

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realPath));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($autoloaderRoot, '', $file->getRealPath());
            $classPath = substr(ltrim($relativePath, DIRECTORY_SEPARATOR), 0, -4);
            $className = $rootNamespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $classPath);

            // !! class_exists выполняет исследуемый файл, поэтому нельзя класть в подключаемые папки обычные скрипты, типо cron.php
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            $isButton = $reflection->isSubclassOf(BaseButton::class);

            $asButtonAttr = $reflection->getAttributes(AsButton::class)[0] ?? null;
            if ($asButtonAttr) {
                if ($isButton) {
                    $instance = $asButtonAttr->newInstance();
                    $actionName = $instance->payload ?? $reflection->getShortName();
                    $this->routeMap['payload'][$actionName] = $className;
                } else {
                    trigger_error(
                        "Attribute #[AsButton] can only be used on classes that extend BaseButton. Class: '{$className}'.",
                        E_USER_WARNING
                    );
                }
            }

            $triggerAttrs = $reflection->getAttributes(Trigger::class);
            foreach ($triggerAttrs as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance->command) {
                    if (isset($this->routeMap['command'][$instance->command])) {
                        throw new LogicException(
                            "Duplicate command route '{$instance->command}'. ".
                            "It is already handled by '{$this->routeMap['command'][$instance->command]}'. ".
                            "Conflict with '{$className}'."
                        );
                    }
                    $this->routeMap['command'][$instance->command] = $className;
                }
                if ($instance->pattern) {
                    if (isset($this->routeMap['regex'][$instance->pattern])) {
                        throw new LogicException(
                            "Duplicate pattern route '{$instance->pattern}'. ".
                            "It is already handled by '{$this->routeMap['regex'][$instance->pattern]}'. ".
                            "Conflict with '{$className}'."
                        );
                    }
                    $this->routeMap['regex'][$instance->pattern] = $className;
                }
            }

            if ($reflection->getAttributes(Fallback::class)) {
                if ($this->fallbackAction !== null) {
                    throw new \LogicException(
                        "Duplicate fallback handler. It is already handled by '{$this->fallbackAction}'. ".
                        "Conflict with '{$className}'."
                    );
                }
                $this->fallbackAction = $className;
            }
        }
    }

    /**
     * Собирает и возвращает отладочную информацию о состоянии диспетчера.
     * Полезно для проверки, какие маршруты были зарегистрированы.
     *
     * @return array Массив с отладочной информацией.
     */
    public function debug(): array
    {
        // Убедимся, что маршруты загружены, чтобы получить актуальную информацию
        if (!$this->isRoutesLoaded) {
            try {
                $this->loadRoutes();
            } catch (\Exception $e) {
                // Если при загрузке роутов произошла ошибка, добавим ее в вывод
                return [
                    'error' => 'Failed to load routes: ' . $e->getMessage(),
                    'config' => (array) $this->config,
                    'actions_paths' => $this->actionPaths
                ];
            }
        }

        return [
            'is_routes_loaded' => $this->isRoutesLoaded,
            'actions_paths' => $this->actionPaths,
            'config' => [
                'has_factory' => $this->factory !== null,
                'has_cache' => isset($this->config->cache),
                'root_namespace' => $this->config->rootNamespace ?? null,
                'autoloader_root' => $this->config->autoloader_root ?? null,
            ],
            'routes' => [
                'payload' => $this->routeMap['payload'],
                'command' => $this->routeMap['command'],
                'regex' => $this->routeMap['regex'],
                'fallback' => $this->fallbackAction,
            ],
        ];
    }
}