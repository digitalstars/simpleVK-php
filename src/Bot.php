<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Event\UpdateType;
use DigitalStars\SimpleVK\LongPoll\LongPollClient;
use DigitalStars\SimpleVK\Message\IncomingMessage;
use Throwable;

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

    /** @var \Closure(IncomingMessage):void|null */
    private ?\Closure $onMessageHandler = null;

    /** @var \Closure(array<string,mixed>):void|null */
    private ?\Closure $onCallbackHandler = null;

    /** @var \Closure(Update):void|null */
    private ?\Closure $fallback = null;

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
        $this->onMessageHandler = $handler(...);

        return $this;
    }

    /**
     * Обработчик нажатий callback-кнопок (message_event).
     *
     * @param callable(array<string, mixed>): void $handler Сырой объект события.
     */
    public function onCallback(callable $handler): self
    {
        $this->onCallbackHandler = $handler(...);

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
        $this->fallback = $handler(...);

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
     *
     * При заданном в конфиге PSR-16 кэше дубликаты событий (reconnect LongPoll,
     * ретраи вебхуков) отбрасываются по event_id с TTL из кэша.
     */
    public function dispatch(array|Update $update): void
    {
        $dto = $update instanceof Update ? $update : Update::fromLongPoll($update);

        if ($this->config->cache !== null) {
            $dedupKey = 'svk4_evt_' . $dto->groupId . '_' . $dto->eventId;
            try {
                if ($this->config->cache->has($dedupKey)) {
                    return;
                }
                $this->config->cache->set($dedupKey, true, 259_200);
            } catch (\Psr\SimpleCache\InvalidArgumentException) {
                // Кэш недоступен — обрабатываем событие как обычно.
            }
        }

        // Свёртка middleware: последний зарегистрированный выполняется первым.
        $pipeline = $this->route(...);
        foreach (\array_reverse($this->middleware) as $middleware) {
            $next = $pipeline;
            $pipeline = static fn(Update $u) => $middleware($u, $next);
        }

        $pipeline($dto);
    }

    /**
     * Блокирующий LongPoll-цикл (для скриптов и true-async корутин).
     *
     * Устойчив к сбоям: ошибка одного события или сети логируется,
     * цикл продолжается (с паузой 1с после сетевого сбоя).
     *
     * @param bool $skipBacklog Пропустить события, накопившиеся до старта.
     */
    public function run(bool $skipBacklog = false): void
    {
        $longpoll = new LongPollClient($this->config, $this->api);

        if ($skipBacklog) {
            $longpoll->skipBacklog();
        }

        while (true) {
            try {
                $updates = $longpoll->wait();
            } catch (Throwable $e) {
                // Сбой сети/протокола: логируем и делаем паузу, чтобы не крутить холостой цикл.
                $this->config->logger->error('LongPoll: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                \sleep(1);

                continue;
            }

            foreach ($updates as $update) {
                try {
                    $this->dispatch($update);
                } catch (Throwable $e) {
                    // Падение одного события не останавливает обработку остальных.
                    $this->config->logger->error('Обработка события {type} упала: {error}', [
                        'type' => $update->type->value,
                        'event_id' => $update->eventId,
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
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
