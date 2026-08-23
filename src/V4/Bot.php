<?php

namespace DigitalStars\SimpleVK\V4;

use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Event\UpdateType;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\LongPoll\LongPollClient;
use DigitalStars\SimpleVK\V4\Message\IncomingMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Бот: регистрация обработчиков, middleware и диспетчеризация событий.
 *
 * Один и тот же код обработчиков работает синхронно и на event loop:
 * различие только в транспорте конфига и способе запуска (run() vs dispatch()).
 */
final class Bot
{
    private ApiClient $api;

    /** @var array<string, list<callable(Update): void>> */
    private array $handlers = [];

    /** @var callable|null */
    private $onMessageHandler = null;

    /** @var callable|null */
    private $onCallbackHandler = null;

    /** @var callable|null */
    private $fallback = null;

    /** @var list<callable(Update, callable): void> */
    private array $middleware = [];

    public function __construct(
        public readonly ClientConfig $config,
        ?ApiClient $api = null,
    ) {
        $this->api = $api ?? new ApiClient($config);
    }

    public static function create(ClientConfig $config): self
    {
        return new self($config);
    }

    /**
     * Обработчик всех входящих сообщений (message_new).
     *
     * @param callable(IncomingMessage): void $handler
     */
    public function onMessage(callable $handler): self
    {
        $this->onMessageHandler = $handler;

        return $this;
    }

    /**
     * Обработчик нажатий callback-кнопок (message_event).
     *
     * @param callable(array<string, mixed>): void $handler Сырой объект события.
     */
    public function onCallback(callable $handler): self
    {
        $this->onCallbackHandler = $handler;

        return $this;
    }

    /**
     * Обработчик конкретного типа событий; повторная регистрация того же типа заменяет предыдущий.
     *
     * @param callable(Update): void $handler
     */
    public function on(UpdateType|string $type, callable $handler): self
    {
        $key = $type instanceof UpdateType ? $type->value : $type;
        $this->handlers[$key] ??= [];
        $this->handlers[$key][] = $handler;

        return $this;
    }

    /**
     * Обработчик событий без зарегистрированного типа.
     *
     * @param callable(Update): void $handler
     */
    public function onFallback(callable $handler): self
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * Middleware оборачивает диспетчеризацию: логирование, троттлинг, метрики.
     *
     * @param callable(Update, callable): void $middleware Вызовите $next($update) для продолжения.
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Точка входа для внешних источников: вебхук, Swoole/RoadRunner worker, тесты.
     */
    public function dispatch(array|Update $update): void
    {
        $dto = $update instanceof Update ? $update : Update::fromLongPoll($update);

        // Свёртка middleware: последний зарегистрированный выполняется первым.
        $pipeline = fn(Update $u) => $this->route($u);
        foreach (\array_reverse($this->middleware) as $middleware) {
            $next = $pipeline;
            $pipeline = fn(Update $u) => $middleware($u, $next);
        }

        $pipeline($dto);
    }

    /**
     * Блокирующий LongPoll-цикл (для скриптов и true-async корутин).
     */
    public function run(): void
    {
        $longpoll = new LongPollClient($this->config, $this->api);

        while (true) {
            foreach ($longpoll->wait() as $update) {
                $this->dispatch($update);
            }
        }
    }

    /**
     * Клиент API для прямых вызовов ($bot->api()->call('users.get')).
     */
    public function api(): ApiClient
    {
        return $this->api;
    }

    private function route(Update $update): void
    {
        match ($update->type) {
            UpdateType::MessageNew => $this->handleMessageNew($update),
            UpdateType::Callback => $this->handleCallbackEvent($update),
            default => $this->handleTyped($update),
        };
    }

    private function handleMessageNew(Update $update): void
    {
        if ($this->onMessageHandler === null) {
            return;
        }

        ($this->onMessageHandler)(new IncomingMessage(
            update: $update,
            api: $this->api,
            groupId: $update->groupId ?: $this->config->groupId,
        ));
    }

    private function handleCallbackEvent(Update $update): void
    {
        if ($this->onCallbackHandler !== null) {
            ($this->onCallbackHandler)($update->object());
        }
    }

    private function handleTyped(Update $update): void
    {
        $specifics = $this->handlers[$update->type->value] ?? [];

        if ($specifics !== []) {
            foreach ($specifics as $handler) {
                $handler($update);
            }

            return;
        }

        if ($this->fallback !== null) {
            ($this->fallback)($update);

            return;
        }
    }
}
