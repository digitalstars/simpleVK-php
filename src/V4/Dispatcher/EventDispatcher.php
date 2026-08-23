<?php

namespace DigitalStars\SimpleVK\V4\Dispatcher;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\AsButton;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Fallback;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\Trigger;
use DigitalStars\SimpleVK\V4\Dispatcher\Attributes\UseMiddleware;
use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Message\IncomingMessage;
use LogicException;
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
    /** @var array{payload: array<string, class-string>, command: array<string, class-string>, regex: array<string, class-string>} */
    private array $routeMap = [
        'payload' => [],
        'command' => [],
        'regex' => [],
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
     * @return array{actionClass: class-string, actionArgs: array<string, mixed>}|null
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
                if (\preg_match($pattern, $text, $matches) === 1) {
                    return [
                        'actionClass' => $className,
                        'actionArgs' => \array_slice($matches, 1),
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
     * Создаёт экземпляр Action и запускает его через middleware-пайплайн.
     *
     * @param class-string $actionClass
     * @param array<string, mixed> $actionArgs
     */
    public function runAction(string $actionClass, Context $context, array $actionArgs = []): void
    {
        $instance = $this->createInstance($actionClass, $context);
        $reflectionClass = new ReflectionClass($actionClass);
        $previousAction = $context->actionClass;
        $context->actionClass = $actionClass;

        try {
            // Middleware: глобальные применяются только на верхнем запуске,
            // при Context::run() вложенный Action получает только свои #[UseMiddleware].
            $globalAlreadyApplied = $this->globalMiddlewareApplied;
            $this->globalMiddlewareApplied = true;

            $actionMiddleware = \array_map(
                static fn($attr): string => $attr->newInstance()->middleware,
                $reflectionClass->getAttributes(UseMiddleware::class),
            );

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
                function (callable $next, string $middlewareClass) use ($context): callable {
                    return function (Context $ctx) use ($next, $middlewareClass): void {
                        /** @var MiddlewareInterface $middlewareInstance */
                        $middlewareInstance = $this->createInstance($middlewareClass, $ctx);
                        $middlewareInstance->process($ctx, $next);
                    };
                },
                $finalHandler,
            );

            $pipeline($context);
        } finally {
            $context->actionClass = $previousAction;
        }
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

        \assert(\class_exists($className));

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
            $instance = $reflection->newInstanceArgs($constructorArgs);

            return $instance;
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
            \assert($fileInfo instanceof \SplFileInfo);

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
