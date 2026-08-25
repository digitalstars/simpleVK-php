<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Dispatcher;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Dispatcher\Attributes\AsButton;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Fallback;
use DigitalStars\SimpleVK\Dispatcher\Attributes\OnException;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Throttle;
use DigitalStars\SimpleVK\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\Dispatcher\Attributes\UseMiddleware;
use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Message\IncomingMessage;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

/**
 * Диспетчер событий: роутинг по атрибутам Action-классов и запуск через middleware.
 *
 * Приоритет маршрутизации: payload-кнопки → команды → regex-паттерны → #[Fallback].
 */
class EventDispatcher
{
    /** @var array{payload: array<string, class-string>, command: array<string, class-string>, regex: array<string, class-string>, exception: array<class-string, class-string>} */
    private array $routeMap = [
        'payload' => [],
        'command' => [],
        'regex' => [],
        'exception' => [],
    ];

    private ?string $fallbackAction = null;

    /** @var array<string, true> */
    private array $scannedFiles = [];

    private bool $globalMiddlewareApplied = false;

    public function __construct(
        private readonly DispatcherConfig $config,
        private readonly ArgumentResolver $argumentResolver,
        /** Нужен для построения Context у не-message событий (callback и т.п.). */
        private readonly ?ApiClient $api = null,
        /** PSR-16 кэш для #[Throttle]; обязателен, если хоть один Action его использует. */
        private readonly ?CacheInterface $cache = null,
    ) {
        foreach ($this->config->actionsPaths as $path) {
            $this->scanDirectoryForActions($path);
        }
    }

    /**
     * Обрабатывает событие: строит Context, находит маршрут и запускает Action.
     *
     * @param IncomingMessage|Update|array<string, mixed> $event Событие из Bot::dispatch или сырое.
     */
    public function handle(IncomingMessage|Update|array $event): void
    {
        if ($event instanceof IncomingMessage) {
            $payload = $event->payload();
            $rawText = $event->text();
            $text = \is_string($rawText) ? $rawText : null;

            $context = new Context(
                api: $event->api(),
                dispatcher: $this,
                argumentResolver: $this->argumentResolver,
                event: $event,
                userId: $event->senderId(),
                peerId: $event->peerId(),
                messageText: $text,
                factory: $this->config->getFactory(),
            );
        } else {
            if (\is_array($event)) {
                $event = Update::fromLongPoll($event);
            }

            // Для callback-кнопок payload лежит в object.payload, для остальных событий — нет.
            $object = $event->object();
            $payloadJson = $object['payload'] ?? null;
            $payload = null;
            if (\is_string($payloadJson) && $payloadJson !== '') {
                try {
                    $decoded = \json_decode($payloadJson, true, flags: \JSON_THROW_ON_ERROR);
                    $payload = \is_array($decoded) ? $decoded : null;
                } catch (\JsonException) {
                    $payload = null;
                }
            }

            if ($this->api === null) {
                throw new LogicException('Для не-message событий передайте ApiClient в конструктор EventDispatcher');
            }

            $userId = isset($object['from_id']) && is_numeric($object['from_id']) ? (int) $object['from_id'] : null;

            $context = new Context(
                api: $this->api,
                dispatcher: $this,
                argumentResolver: $this->argumentResolver,
                event: $object,
                userId: $userId,
                peerId: isset($object['peer_id']) && is_numeric($object['peer_id']) ? (int) $object['peer_id'] : null,
                messageText: \is_string($object['text'] ?? null) ? $object['text'] : null,
                factory: $this->config->getFactory(),
            );
            $text = $context->messageText;
        }

        $route = $this->findRoute($text, $payload);

        if ($route === null) {
            if ($this->config->debug) {
                $payloadJson = $payload === null ? 'null' : \json_encode($payload, \JSON_UNESCAPED_UNICODE);
                \trigger_error(
                    "Диспетчер: не найден маршрут. Text: '{$text}', Payload: {$payloadJson}. Fallback не настроен.",
                    E_USER_WARNING,
                );
            }

            return;
        }

        $this->runAction($route['actionClass'], $context, $route['actionArgs']);
    }

    /**
     * Находит подходящий Action по тексту и payload.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array{actionClass: class-string, actionArgs: array<int|string, mixed>}|null
     */
    private function findRoute(?string $text, ?array $payload): ?array
    {
        // Приоритет 1: payload-кнопки (ключ 'action')
        if (isset($payload['action']) && \is_string($payload['action'])) {
            $actionName = $payload['action'];
            if (isset($this->routeMap['payload'][$actionName])) {
                return [
                    'actionClass' => $this->routeMap['payload'][$actionName],
                    'actionArgs' => \array_diff_key($payload, ['action' => '']),
                ];
            }
        }

        if ($text !== null && $text !== '') {
            // Приоритет 2: точная команда
            if (isset($this->routeMap['command'][$text])) {
                return ['actionClass' => $this->routeMap['command'][$text], 'actionArgs' => []];
            }

            // Приоритет 3: regex-паттерны (в порядке регистрации)
            foreach ($this->routeMap['regex'] as $pattern => $className) {
                $matches = [];
                if (\preg_match($pattern, $text, $matches, \PREG_UNMATCHED_AS_NULL) === 1) {
                    return [
                        'actionClass' => $className,
                        'actionArgs' => self::extractMatchArgs($matches),
                    ];
                }
            }
        }

        // Приоритет 4: Fallback
        if ($this->fallbackAction !== null) {
            return ['actionClass' => $this->fallbackAction, 'actionArgs' => []];
        }

        return null;
    }

    /**
     * Именованные группы (?P<name>...) уходят в аргументы по имени,
     * без них — позиционные группы как раньше.
     *
     * @param array<int|string, string|null> $matches
     *
     * @return array<string|int, mixed>
     */
    private static function extractMatchArgs(array $matches): array
    {
        $named = [];
        foreach ($matches as $key => $value) {
            if (\is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named !== [] ? $named : \array_slice($matches, 1);
    }

    /**
     * Создаёт экземпляр Action и запускает его через middleware-пайплайн.
     *
     * @param class-string $actionClass
     * @param array<int|string, mixed> $actionArgs
     */
    public function runAction(string $actionClass, Context $context, array $actionArgs = []): void
    {
        $this->runActionInternal($actionClass, $context, $actionArgs, allowExceptionRouting: true);
    }

    /**
     * @param class-string $actionClass
     * @param array<string|int, mixed> $actionArgs
     */
    private function runActionInternal(
        string $actionClass,
        Context $context,
        array $actionArgs,
        bool $allowExceptionRouting,
    ): void {
        $reflectionClass = new ReflectionClass($actionClass);

        if ($allowExceptionRouting && !$this->checkThrottle($reflectionClass, $context)) {
            return; // лимит исчерпан — событие отброшено
        }

        $instance = $this->createInstance($actionClass, $context);
        $previousAction = $context->actionClass;
        $context->actionClass = $actionClass;

        try {
            // Middleware: глобальные применяются только на верхнем запуске,
            // при Context::run() вложенный Action получает только свои #[UseMiddleware].
            $globalAlreadyApplied = $this->globalMiddlewareApplied;
            $this->globalMiddlewareApplied = true;

            $actionMiddleware = self::inheritedMiddlewares($reflectionClass);

            $middlewareStack = $globalAlreadyApplied
                ? $actionMiddleware
                : [...$this->config->getMiddleware(), ...$actionMiddleware];

            $finalHandler = function (Context $ctx) use ($instance, $reflectionClass, $actionClass, $actionArgs): void {
                if (\method_exists($instance, 'before')) {
                    $beforeMethod = $reflectionClass->getMethod('before');
                    $beforeArgs = $this->argumentResolver->getArguments($beforeMethod, $ctx, $actionArgs);
                    if ($instance->before(...$beforeArgs) === false) {
                        return;
                    }
                }

                if (!\method_exists($instance, 'handle')) {
                    throw new RuntimeException("Класс {$actionClass} должен иметь метод handle()");
                }

                $handleMethod = $reflectionClass->getMethod('handle');
                $resolvedArgs = $this->argumentResolver->getArguments($handleMethod, $ctx, $actionArgs);
                $instance->handle(...$resolvedArgs);
            };

            $pipeline = \array_reduce(
                \array_reverse($middlewareStack),
                fn(callable $next, string $middlewareClass): callable => function (Context $ctx) use (
                    $next,
                    $middlewareClass,
                ): void {
                    /** @var MiddlewareInterface $middlewareInstance */
                    $middlewareInstance = $this->createInstance($middlewareClass, $ctx);
                    $middlewareInstance->process($ctx, $next);
                },
                $finalHandler,
            );

            try {
                $pipeline($context);
            } catch (Throwable $e) {
                if (!$allowExceptionRouting || !$this->routeException($e, $context)) {
                    throw $e;
                }
            }
        } finally {
            $context->actionClass = $previousAction;
        }
    }

    /**
     * Middleware текущего класса + всех предков (базовый класс задаёт scope).
     *
     * @return list<class-string>
     */
    private static function inheritedMiddlewares(ReflectionClass $class): array
    {
        $middlewares = [];
        for ($c = $class; $c !== false; $c = $c->getParentClass()) {
            foreach (\array_reverse($c->getAttributes(UseMiddleware::class)) as $attribute) {
                $middleware = $attribute->newInstance()->middleware;
                $middlewares[$middleware] ??= $middleware;
            }
        }

        return \array_values($middlewares);
    }

    /**
     * Проверяет #[Throttle] класса (и предков). true — можно выполнять.
     */
    private function checkThrottle(ReflectionClass $class, Context $context): bool
    {
        $throttles = [];
        for ($c = $class; $c !== false; $c = $c->getParentClass()) {
            foreach ($c->getAttributes(Throttle::class) as $attribute) {
                $throttles[] = $attribute->newInstance();
            }
        }

        if ($throttles === []) {
            return true;
        }

        if ($this->cache === null) {
            throw new LogicException(
                "#[Throttle] на '{$class->getName()}' требует PSR-16 кэш "
                . '(4-й аргумент EventDispatcher или ClientConfig::withCache())',
            );
        }

        foreach ($throttles as $throttle) {
            $key = 'svk4_rl_' . \md5($class->getName()) . '_' . ($context->userId ?? 'anon');
            try {
                $bucket = $this->cache->get($key);
                $now = \time();

                if (!\is_array($bucket) || !isset($bucket['count'], $bucket['reset']) || $now >= $bucket['reset']) {
                    $this->cache->set(
                        $key,
                        ['count' => 1, 'reset' => $now + $throttle->windowSeconds],
                        $throttle->windowSeconds,
                    );
                    continue;
                }

                if ((int) $bucket['count'] >= $throttle->max) {
                    if ($this->config->debug) {
                        \trigger_error(
                            "Throttle: '{$class->getName()}' для пользователя {$context->userId} отклонён",
                            E_USER_WARNING,
                        );
                    }

                    return false;
                }

                ++$bucket['count'];
                $this->cache->set($key, $bucket, \max(1, (int) $bucket['reset'] - $now));
            } catch (\Psr\SimpleCache\InvalidArgumentException) {
                // Кэш недоступен — не блокируем обработку.
            }
        }

        return true;
    }

    /**
     * Ищет обработчик исключения от наиболее специфичного класса к базовым.
     */
    private function routeException(Throwable $e, Context $context): bool
    {
        for ($class = $e::class; $class !== false; $class = \get_parent_class($class)) {
            $handlerClass = $this->routeMap['exception'][$class] ?? null;
            if ($handlerClass !== null) {
                $this->runActionInternal($handlerClass, $context, ['exception' => $e], allowExceptionRouting: false); // защита от циклов: ошибка в обработчике уходит наверх

                return true;
            }
        }

        return false;
    }

    /**
     * Создаёт экземпляр класса через DI-фабрику, иначе через рефлексию конструктора.
     *
     * @param class-string $className
     */
    public function createInstance(string $className, Context $context): object
    {
        try {
            return $context->get($className);
        } catch (Throwable) {
            // Фабрики нет или она не знает класс — создаём вручную ниже.
        }

        \assert(\class_exists($className), "Класс {$className} должен существовать");

        if (!\class_exists($className)) {
            throw new RuntimeException("Диспетчер: класс '{$className}' не существует");
        }

        $reflection = new ReflectionClass($className);

        try {
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return $reflection->newInstance();
            }

            $constructorArgs = $this->argumentResolver->getArguments($constructor, $context);

            /** @var object */
            return $reflection->newInstanceArgs($constructorArgs);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Диспетчер: не удалось создать '{$className}'. Проверьте конструктор и DI. Ошибка: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Рекурсивно сканирует директорию и регистрирует маршруты по атрибутам.
     */
    private function scanDirectoryForActions(string $path): void
    {
        $realPath = \realpath($path);

        if ($realPath === false || !\is_dir($realPath)) {
            throw new LogicException("Диспетчер: директория actions не найдена: '{$path}'");
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $realPath,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $fileInfo) {
            \assert($fileInfo instanceof \SplFileInfo, 'Итератор возвращает SplFileInfo');

            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $filePath = $fileInfo->getRealPath();
            if ($filePath === false) {
                continue;
            }
            if (isset($this->scannedFiles[$filePath])) {
                continue;
            }
            $this->scannedFiles[$filePath] = true;

            $className = self::getClassNameFromFile($filePath);
            if ($className === null || !\class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (ReflectionException $e) {
                throw new LogicException(
                    "Диспетчер: класс '{$className}' из файла '{$filePath}' не загрузился. "
                    . "Проверьте PSR-4 соответствие и 'composer dump-autoload'. Ошибка: {$e->getMessage()}",
                );
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            $this->registerRoutesForClass($reflection);
        }
    }

    private function registerRoutesForClass(ReflectionClass $reflection): void
    {
        $className = $reflection->getName();
        $isButton = $reflection->isSubclassOf(BaseButton::class);

        $asButtonAttr = $reflection->getAttributes(AsButton::class)[0] ?? null;
        if ($asButtonAttr !== null) {
            if (!$isButton) {
                throw new LogicException(
                    "Атрибут #[AsButton] допустим только на наследниках BaseButton. Класс: '{$className}'",
                );
            }

            $attr = $asButtonAttr->newInstance();
            $actionName = $attr->payload ?? $reflection->getShortName();
            $this->routeMap['payload'][$actionName] = $className;
        }

        if ($reflection->getAttributes(Throttle::class) !== [] && $this->cache === null) {
            throw new LogicException(
                "#[Throttle] на '{$className}' требует PSR-16 кэш "
                . '(4-й аргумент EventDispatcher или ClientConfig::withCache())',
            );
        }

        foreach ($reflection->getAttributes(Trigger::class) as $attribute) {
            $trigger = $attribute->newInstance();

            if ($trigger->command !== null) {
                if (isset($this->routeMap['command'][$trigger->command])) {
                    $existing = $this->routeMap['command'][$trigger->command];
                    throw new LogicException(
                        "Дублирующаяся команда '{$trigger->command}': '{$existing}' и '{$className}'",
                    );
                }
                $this->routeMap['command'][$trigger->command] = $className;
            }

            if ($trigger->pattern !== null) {
                if (isset($this->routeMap['regex'][$trigger->pattern])) {
                    $existing = $this->routeMap['regex'][$trigger->pattern];
                    throw new LogicException(
                        "Дублирующийся regex-паттерн '{$trigger->pattern}': '{$existing}' и '{$className}'",
                    );
                }
                $this->routeMap['regex'][$trigger->pattern] = $className;
            }
        }

        foreach ($reflection->getAttributes(OnException::class) as $attribute) {
            $onException = $attribute->newInstance();

            if (isset($this->routeMap['exception'][$onException->exception])) {
                $existing = $this->routeMap['exception'][$onException->exception];
                throw new LogicException(
                    "Дублирующийся обработчик '{$onException->exception}': '{$existing}' и '{$className}'",
                );
            }

            $this->routeMap['exception'][$onException->exception] = $className;
        }

        if ($reflection->getAttributes(Fallback::class) !== []) {
            if ($this->fallbackAction !== null) {
                throw new LogicException("Дублирующийся #[Fallback]: '{$this->fallbackAction}' и '{$className}'");
            }
            $this->fallbackAction = $className;
        }
    }

    /**
     * Извлекает полное имя первого класса файла лексическим разбором (без загрузки файла).
     */
    private static function getClassNameFromFile(string $filePath): ?string
    {
        $source = \file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $tokens = \PhpToken::tokenize($source);
        $namespace = '';
        $state = 0; // 0 - ищем, 1 - собираем namespace, 2 - ждём имя класса
        $class = '';

        foreach ($tokens as $token) {
            match (true) {
                $state === 0 && $token->is(T_NAMESPACE) => $state = 1,
                $state === 0 && $token->is(T_CLASS) => $state = 2,
                $state === 1 && $token->is([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR]) => $namespace .= $token->text,
                $state === 1 && $token->text === ';' => $state = 0,
                $state === 2 && $token->is(T_STRING) => $class = $token->text,
                default => null,
            };

            if ($class !== '') {
                break;
            }
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? \rtrim($namespace, '\\') . '\\' . $class : $class;
    }

    /**
     * Отладочная карта маршрутов после сканирования.
     *
     * @return array{routes: array{payload: array<string, class-string>, command: array<string, class-string>, regex: array<string, class-string>, fallback: ?class-string}}
     */
    public function debug(): array
    {
        return [
            'routes' => [
                'payload' => $this->routeMap['payload'],
                'command' => $this->routeMap['command'],
                'regex' => $this->routeMap['regex'],
                'fallback' => $this->fallbackAction,
            ],
        ];
    }
}
