# SimpleVK v4 — черновик пользовательского API

Статус: НА УТВЕРЖДЕНИЕ. Это примеры того, как будет выглядеть код пользователя.
Ничего из этого ещё не реализовано. После утверждения — реализация строго по этим примерам.

## Принципы дизайна
- Всё внедряется через конструктор: транспорт (PSR-18), логгер (PSR-3), кэш (PSR-16) — легко мокать.
- Конфигурация — иммутабельный DTO `ClientConfig` (никаких сеттеров на живом клиенте).
- ENV-first: `ClientConfig::fromEnv()` читает `SIMPLEVK_TOKEN`, `SIMPLEVK_GROUP_ID`, `SIMPLEVK_API_VERSION`.
- Ядро не знает про блокирующий I/O: транспорт — интерфейс `Transport`, синхронная и асинхронная реализации.
- Единый event loop-совместимый контракт: `Bot::run()` для скриптов, `$bot->handleUpdate($dto)` для вебхуков/Swoole/RoadRunner.

---

## 1. Минимальный эхо-бот (синхронный, LongPoll)

```php
use DigitalStars\SimpleVK\V4\{Bot, ClientConfig};

$config = ClientConfig::fromEnv();          // токен/groupId из окружения
$bot = Bot::create($config);

$bot->onMessage(fn(IncomingMessage $msg) => $msg->reply('Эхо: ' . $msg->text));

$bot->run();   // LongPoll-цикл; Ctrl+C для остановки
```

## 2. Полный конфиг с зависимостями (продакшн)

```php
use DigitalStars\SimpleVK\V4\{Bot, ClientConfig};
use DigitalStars\SimpleVK\V4\Transport\{PsrTransport, CurlTransport};
use Psr\Log\LoggerInterface;

$config = ClientConfig::create(
    token: $_ENV['VK_TOKEN'],
    groupId: 123456,
    apiVersion: '5.199',
)
    ->withTransport(new PsrTransport($psr18Client, $requestFactory)) // Guzzle/Symfony/…
    ->withLogger($logger)                                            // любой PSR-3
    ->withCache($psr16Cache)                                         // токен Auth, дедуп событий
    ->withRetry(maxAttempts: 3, backoffMs: 500)
    ->withRateLimit(20);                                             // запросов/сек

$bot = new Bot($config);
```

## 3. Сообщения, клавиатура, вложения

```php
$bot->onMessage(function (IncomingMessage $msg) use ($bot) {
    $msg->text('Привет! Вот что я умею:')
        ->keyboard(Keyboard::make()
            ->row(Button::text('Справка', payload: ['cmd' => 'help'])->colorPrimary())
            ->row(Button::callback('Позвать админа', payload: ['cmd' => 'call_admin']))
        )
        ->attachment('photo123_456')
        ->send();

    // Ответ через клиент напрямую (без контекста входящего):
    $bot->messages()->send(
        userId: $msg->userId,
        text: 'Дублирую в личку',
        keyboard: Keyboard::make()->row(Button::link('Сайт', 'https://example.com')),
    );
});
```

## 4. Карусель

```php
$carousel = Carousel::make()
    ->element(
        title: 'Товар 1',
        description: 'Описание',
        photoId: 'photo-1_1',
        button: Button::text('Купить', payload: ['buy' => 1]),
    )
    ->element(/* … */);

$msg->text('Каталог')->carousel($carousel)->send();
```

## 5. Роутинг атрибутами (аналог v3 #[Trigger]/#[AsButton])

```php
use DigitalStars\SimpleVK\V4\Attributes\{OnCommand, OnPayload, Fallback};

final class OrderHandlers extends HandlerSet
{
    public function __construct(private readonly OrderService $orders) {} // DI через резолвер

    #[OnCommand('заказы')]
    #[OnPayload(['action' => 'my_orders'])]
    public function listOrders(IncomingMessage $msg, Context $ctx): void
    {
        $msg->text('Ваши заказы: ' . $this->orders->listFor($msg->userId))->send();
    }

    #[Fallback]
    public function anythingElse(IncomingMessage $msg): void
    {
        $msg->text('Не понял команду. /help — список')->send();
    }
}

$bot->register(new OrderHandlers($container->get(OrderService::class)));
```

## 6. Колбэк-кнопки и события групп

```php
$bot->onCallback(function (CallbackEvent $e) {
    $e->answer(text: 'Принято');                       // messages.sendMessageEventAnswer
    $e->sourceMessage()->edit(text: 'Готово!')->send();
});

$bot->on(GroupEvent::WallReplyNew, fn(WallReplyNew $e) => /* комментарий на стене */);
```

## 7. Вебхук (Callback API) — для Swoole/RoadRunner/FrankenPHP

Никакого своего HTTP-сервера в библиотеке: бот принимает уже разобранный апдейт.

```php
// Внутри любого фреймворка/сервера:
$bot = Bot::create(ClientConfig::fromEnv()->withConfirmationSecret($_ENV['VK_SECRET']));

$app->post('/vk/callback', function (Request $request) use ($bot) {
    return $bot->handleWebhook($request->getContent(), $request->headers->get('X-Retry-Counter'));
});
```

## 8. Асинхронность: Amp (Revolt) — тот же API, другой транспорт

```php
use DigitalStars\SimpleVK\V4\Async\AmpAdapter;

$config = ClientConfig::fromEnv()
    ->withTransport(AmpAdapter::transport());      // amphp/http-client

$bot = new Bot($config);

$bot->onMessage(fn(IncomingMessage $m) => $m->text('Асинхронное эхо')->send());

AmpAdapter::run($bot);   // LongPoll на Revolt event loop, без блокировки
// true-async: Async\spawn(fn() => $bot->run()) — см. §9
```

## 9. True-async (experimental)

```php
use func php\async\spawn; // расширение true-async

$bot = Bot::create(ClientConfig::fromEnv());
$bot->onMessage(fn(IncomingMessage $m) => $m->text('Эхо')->send());

spawn(fn() => $bot->run());       // неблокирующая корутина
// основной поток продолжает работать
```

## 10. Middleware (логирование, троттлинг, свои обёртки)

```php
$bot->middleware(function (Update $update, callable $next) use ($logger) {
    $logger->info('update', ['type' => $update->type]);
    return $next($update);
});
```

## 11. Моки в тестах пользователя (главная цель типизации)

```php
$transport = new FakeTransport([                 // тестовая заглушка из состава библиотеки
    'messages.send' => ['response' => ['message_id' => 42]],
]);
$bot = Bot::create(ClientConfig::create(token: 'test', groupId: 1)->withTransport($transport));

$bot->onMessage(fn(IncomingMessage $m) => $m->text('ok')->send());
$bot->dispatch(TestData::message(text: '/start'));  // фабрика тестовых апдейтов

assert($transport->calls[0]['method'] === 'messages.send');
```

---

## Что осталось прежним по духу v3
Цепочки `$msg->text(...)->keyboard(...)->send()`, атрибутный роутинг, карусели, Streaming,
Auth/SiteAuth, Store (кэш состояния), Setting, Diagnostics.

## Что ломается относительно v3 (осознанно)
- Пространства имён `DigitalStars\SimpleVK\V4\...`, все классы переименованы
- Кастомный автозагрузчик удалён — только Composer (+ vendor-архив к релизу)
- MIN PHP 8.4, строгая типизация везде
