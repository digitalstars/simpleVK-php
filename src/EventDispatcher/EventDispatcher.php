<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use Closure;
use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\Attributes\{AsButton, Trigger, Fallback, UseMiddleware};
use LogicException;
use PhpToken;
use ReflectionClass;
use RecursiveIteratorIterator, RecursiveDirectoryIterator;
use ReflectionException;
use RuntimeException;
use Throwable;

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
    private ?Closure $factory;
    private ArgumentResolver $argumentResolver;
    private array $scannedFiles = [];

    public function __construct(SimpleVK $vk, DispatcherConfig $config)
    {
        $this->vk = $vk;
        $this->config = $config;
        $this->factory = $config->getFactory();
        $this->argumentResolver = new ArgumentResolver($config->cache ?? null);
        $this->loadRoutesFromConfig();
    }

    private function loadRoutesFromConfig(): void
    {
        foreach ($this->config->actionsPaths as $path) {
            $this->scanDirectoryForActions($path);
        }

        $routeCount = count($this->routeMap['payload']) + count($this->routeMap['command']) + count($this->routeMap['regex']);
        if ($routeCount === 0 && $this->fallbackAction === null) {
            throw new LogicException(
                "Диспетчер: Сканирование маршрутов завершено, но не найдено ни одного маршрута или fallback-обработчика. " .
                "Убедитесь, что ваши классы-обработчики (Action) имеют атрибуты #[Trigger], #[AsButton] или #[Fallback] и находятся в правильном пространстве имен.",
                0
            );
        }
    }

    public function handle(?array $externalEvent = null): void
    {
        if(is_array($externalEvent) && empty($externalEvent)) {
            trigger_error(
                "Диспетчер: Метод handle() был вызван с пустым массивом событий. Обработка прекращена.",
                E_USER_NOTICE
            );
            return;
        }

        $event = $externalEvent ?? $this->vk->data;
        $this->vk->data = $event;
        $this->vk->initText($text)->initUserID($userId)->initPayload($payload)->initType($eventType);

        if (is_null($userId)) {
            if($this->config->debug){
                trigger_error(
                    "Диспетчер: Получено событие типа '{$eventType}' без user_id. Обработка пропущена.",
                    E_USER_NOTICE
                );
            }
//
            return;
        }

        $route = $this->findRoute($text, $payload);

        $actionClass = $route['actionClass'] ?? $this->fallbackAction;
        $actionArgs = $route['actionArgs'] ?? [];

        if (!$actionClass) {
            $payloadJson = json_encode($payload);
            trigger_error(
                "Диспетчер: Не найден подходящий маршрут для пользователя '{$userId}'. Текст: '{$text}', Payload: {$payloadJson}. Резервный обработчик (fallback) не настроен.",
                E_USER_NOTICE
            );
            return;
        }

        $context = $this->createContextFromEvent($event);
        $context->actionClass = $actionClass;
        $this->runAction($actionClass, $context, $actionArgs);
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
        $reflectionClass = new ReflectionClass($actionClass);

        //Собираем все middleware в один массив.
        //Сначала глобальные, потом #[UseMiddleware] из Action
        $globalMiddleware = $this->config->getMiddleware();
        $actionMiddlewareAttrs = $reflectionClass->getAttributes(UseMiddleware::class);
        $actionMiddleware = array_map(static fn($attr) => $attr->newInstance()->middleware, $actionMiddlewareAttrs);

        $middlewareStack = array_merge($globalMiddleware, $actionMiddleware);

        $finalHandler = function (Context $ctx) use ($instance, $reflectionClass, $actionClass, $actionArgs) {
            if (method_exists($instance, 'before')) {
                $reflectionMethod = $reflectionClass->getMethod('before');
                $beforeArgs = $this->argumentResolver->getArguments($reflectionMethod, $ctx, $actionArgs);
                if ($instance->before(...$beforeArgs) === false) {
                    return;
                }
            }

            if (!method_exists($instance, 'handle')) {
                throw new \RuntimeException("Класс {$actionClass} должен иметь метод handle()");
            }

            $reflectionMethod = $reflectionClass->getMethod('handle');
            $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $ctx, $actionArgs);
            $instance->handle(...$resolvedArgs);
        };

        //Собирает конвеер вызовов в виде луковицы. Вызовы сначала глобальных, потом #[UseMiddleware]
        $pipeline = array_reduce(
            array_reverse($middlewareStack),
            function ($next, $middlewareClass) use ($context) {
                return function (Context $ctx) use ($next, $middlewareClass, $context) {
                    /** @var MiddlewareInterface $middlewareInstance */
                    $middlewareInstance = $this->createInstance($middlewareClass, $context);
                    $middlewareInstance->process($ctx, $next);
                };
            },
            $finalHandler
        );

        $pipeline($context);
    }

    /**
     * Создает экземпляр класса, используя фабрику/DI (через Context) и рефлексию.
     * Это универсальный метод для создания Action'ов и View'ов.
     *
     * @template T
     * @param class-string<T> $className
     * @param Context $context
     * @return T
     */
    public function createInstance(string $className, Context $context): object
    {
        $instance = null;

        // Приоритет 1: Попытка создать через пользовательскую фабрику/DI.
        if (is_callable($this->factory)) {
            try {
                $instance = $context->get($className);
            } catch (Throwable $e) {
                if($this->config->debug){
                    trigger_error(
                        "Диспетчер: Настроенная фабрика/DI-контейнер не смог создать экземпляр класса '{$className}'. Ошибка: {$e->getMessage()}",
                        E_USER_WARNING
                    );
                }
            }
        }

        // Приоритет 2: Ручное создание, если фабрика не справилась или ее нет.
        if (!$instance) {
            try {
                $reflectionClass = new ReflectionClass($className);
                $constructor = $reflectionClass->getConstructor();
                if ($constructor) {
                    // Рантайм-аргументы сюда НЕ передаются.
                    $constructorArgs = $this->argumentResolver->getArguments($constructor, $context);
                    $instance = $reflectionClass->newInstanceArgs($constructorArgs);
                } else {
                    $instance = $reflectionClass->newInstance();
                }
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Диспетчер: Не удалось создать экземпляр класса '{$className}' через рефлексию. " .
                    "Проверьте его конструктор и зависимости. Исходная ошибка: " . $e->getMessage(),0, $e
                );
            }

        }
        return $instance;
    }

    private function scanDirectoryForActions(string $path): void
    {
        $realPath = realpath($path);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realPath));

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();

            if (isset($this->scannedFiles[$filePath])) {
                continue;
            }

            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $this->scannedFiles[$filePath] = true;

            $className = $this->getClassNameFromFile($filePath);
            if ($className === null) {
                continue; // Не удалось определить класс в файле
            }

            try {
                // Если класс уже загружен (например, DI-компилятором), ReflectionClass просто создастся.
                // Если нет, будет вызвана автозагрузка. Если автозагрузка не найдет класс, будет выброшено исключение.
                $reflection = new ReflectionClass($className);
            } catch (ReflectionException) {
//                if ($this->config->debug) {
                throw new LogicException(
                    "Диспетчер: Не удалось найти или загрузить класс '{$className}', который был определён в файле '{$filePath}'.\n" .
                    "Убедитесь, что пространство имён (namespace) в файле соответствует его расположению в директории (согласно PSR-4), " .
                    "а также что выполнена команда 'composer dump-autoload'."
                );
//                }
                continue;
            }
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
                    //todo возможно изменить
                    throw new LogicException(
                        "Диспетчер: Атрибут #[AsButton] можно использовать только для классов, наследующих BaseButton. Ошибка в классе: '{$className}'.",
                    );
                }
            }

            $triggerAttrs = $reflection->getAttributes(Trigger::class);
            foreach ($triggerAttrs as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance->command) {
                    if (isset($this->routeMap['command'][$instance->command])) {
                        $existingHandler = $this->routeMap['command'][$instance->command];
                        throw new LogicException(
                            "Дублирующаяся команда '{$instance->command}'.\n" .
                            "Она уже обрабатывается классом '{$existingHandler}'.\n" .
                            "Конфликт с классом '{$className}'."
                        );
                    }
                    $this->routeMap['command'][$instance->command] = $className;
                }
                if ($instance->pattern) {
                    if (isset($this->routeMap['regex'][$instance->pattern])) {
                        $existingHandler = $this->routeMap['regex'][$instance->pattern];
                        throw new LogicException(
                            "Дублирующийся паттерн (регулярное выражение) '{$instance->pattern}'.\n" .
                            "Он уже обрабатывается классом '{$existingHandler}'.\n" .
                            "Конфликт с классом '{$className}'."
                        );
                    }
                    $this->routeMap['regex'][$instance->pattern] = $className;
                }
            }

            if ($reflection->getAttributes(Fallback::class)) {
                if ($this->fallbackAction !== null) {
                    throw new LogicException(
                        "Обнаружен дублирующийся резервный обработчик (Fallback).\n" .
                        "Он уже назначен классу '{$this->fallbackAction}'.\n" .
                        "Конфликт с классом '{$className}'. " .
                        "В системе может быть только один fallback-обработчик."
                    );
                }
                $this->fallbackAction = $className;
            }
        }
    }

    /**
     * Получает полное имя класса (FQCN) из PHP-файла путем парсинга токенов.
     *
     * @param string $filePath Абсолютный путь к PHP-файлу.
     * @return string|null Полное имя класса или null, если не найдено.
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        //отрабатывает ~1ms на ноуте
        $content = file_get_contents($filePath);
        $tokens = PhpToken::tokenize($content);

        $namespace = '';
        $class = '';

        // 0 = начальное состояние
        // 1 = найден токен T_NAMESPACE, собираем имя пространства имен
        // 2 = найден токен T_CLASS, собираем имя класса
        $state = 0;

        foreach ($tokens as $token) {
            if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT)) {
                continue;
            }

            switch ($state) {
                // Ищем начало namespace или class
                case 0:
                    if ($token->is(T_NAMESPACE)) {
                        $state = 1;
                    } elseif ($token->is(T_CLASS)) {
                        $state = 2;
                    }
                    break;

                // Собираем имя namespace
                case 1:
                    if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                        $namespace .= $token->text;
                    } elseif ($token->text === ';') {
                        $state = 0; // Закончили с namespace, ищем дальше
                    }
                    break;

                // Ищем имя класса
                case 2:
                    if ($token->is(T_STRING)) {
                        $class = $token->text;
                        // Класс найден, дальнейший парсинг не нужен
                        break 2;
                    }
                    break;
            }
        }

        if (empty($class)) {
            return null;
        }

        return $namespace ? rtrim($namespace, '\\') . '\\' . $class : $class;
    }

    /**
     * Собирает и возвращает отладочную информацию о состоянии диспетчера.
     * Полезно для проверки, какие маршруты были зарегистрированы.
     *
     * @return array Массив с отладочной информацией.
     */
    public function debug(): array
    {
        $actionRealPaths = array_map('realpath', $this->config->actionsPaths);
        return [
            'config' => [
                'debug_mode' => $this->config->debug,
                'actions_paths' => $actionRealPaths,
                'has_factory' => $this->factory !== null,
                'has_cache' => $this->config->cache !== null,
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