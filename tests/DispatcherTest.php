<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\Tests\tmp_actions\CatchAllAction;
use DigitalStars\SimpleVK\Tests\tmp_actions\PingBtn;
use DigitalStars\SimpleVK\Tests\tmp_actions\StartAction;
use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Dispatcher\ArgumentResolver;
use DigitalStars\SimpleVK\Dispatcher\Context;
use DigitalStars\SimpleVK\Dispatcher\EventDispatcher;
use DigitalStars\SimpleVK\Message\Keyboard;
use DigitalStars\SimpleVK\Service\ErrorReporter;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use DigitalStars\SimpleVK\WebhookHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class DispatcherTest extends TestCase
{
    private string $actionsDir;

    private FakeTransport $fake;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->actionsDir = __DIR__ . '/tmp_actions';
        $this->fake = new FakeTransport(['messages.send' => [['message_id' => 1]]]);

        $this->dispatcher = new EventDispatcher(
            new \DigitalStars\SimpleVK\Dispatcher\DispatcherConfig([$this->actionsDir]),
            new ArgumentResolver(),
            new ApiClient(ClientConfig::create('T', 5), $this->fake),
        );

        PingBtn::$hits = 0;
        PingBtn::$lastContext = null;
        StartAction::$hits = 0;
        CatchAllAction::$lastText = null;
    }

    public function testCommandRouteTriggersAction(): void
    {
        $this->dispatcher->handle($this->incomingMessage('/start'));

        self::assertSame(1, StartAction::$hits);
    }

    public function testPayloadButtonRoute(): void
    {
        // payload action = короткое имя класса (дефолт)
        $msg = $this->incomingMessage('', payload: '{"action":"PingBtn"}');
        $this->dispatcher->handle($msg);

        self::assertSame(1, PingBtn::$hits);
        self::assertNotNull(PingBtn::$lastContext);
    }

    public function testFallbackCatchesUnroutedText(): void
    {
        $this->dispatcher->handle($this->incomingMessage('какой-то текст'));

        self::assertSame('какой-то текст', CatchAllAction::$lastText);
    }

    public function testKeyboardFromButtonsBuildsVkStructureAndRoundTrip(): void
    {
        $btn = new PingBtn();
        $btn->label('Пни меня');
        $btn->addPayload(['extra' => 'value']);

        $keyboard = Keyboard::fromButtons([[$btn]]);

        $array = $keyboard->toArray();
        self::assertCount(1, $array['buttons']);

        $action = $array['buttons'][0][0]['action'];
        self::assertSame('Пни меня', $action['label']);

        // Payload хранится как JSON-строка и содержит action + extra
        self::assertIsString($action['payload']);
        $decoded = \json_decode($action['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['extra' => 'value', 'action' => 'PingBtn'], $decoded);

        // Round-trip: нажатие такой кнопки маршрутизируется в тот же класс
        $before = PingBtn::$hits;
        $this->dispatcher->handle($this->incomingMessage('', payload: $action['payload']));
        self::assertSame($before + 1, PingBtn::$hits);
    }

    public function testContextMsgTargetsCurrentPeer(): void
    {
        $this->dispatcher->handle($this->incomingMessage('', payload: '{"action":"PingBtn"}'));

        $params = $this->fake->lastParamsFor('messages.send');
        self::assertSame(2000000099, $params['peer_id']);
        self::assertSame('pong', $params['message']);
    }

    public function testDedupDropsDuplicateEvents(): void
    {
        $arrayCache = new class implements \Psr\SimpleCache\CacheInterface {
            /** @var array<string, mixed> */
            public array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
        };

        $config = ClientConfig::create('T', 9)->withCache($arrayCache);
        $bot = new \DigitalStars\SimpleVK\Bot($config, new ApiClient($config, $this->fake));
        $bot->onMessage(static function (): void {});

        $raw = ['type' => 'group_join', 'event_id' => 42, 'group_id' => 9, 'object' => []];

        $bot->dispatch($raw);
        $bot->dispatch($raw); // дубль — отбрасывается

        self::assertTrue($arrayCache->has('svk4_evt_9_42'));
    }

    public function testWebhookConfirmationAndSecret(): void
    {
        $config = ClientConfig::create('T', 5)->withConfirmationSecret('s3cret')->withConfirmationCode('CONFIRM123');
        $bot = new \DigitalStars\SimpleVK\Bot($config, new ApiClient($config, $this->fake));
        $handler = new WebhookHandler($bot);

        self::assertSame('CONFIRM123', $handler->handle(\json_encode(['type' => 'confirmation'])));

        try {
            $handler->handle(\json_encode(['type' => 'message_new', 'object' => []]), 'wrong-secret');
            self::fail();
        } catch (\DigitalStars\SimpleVK\Exception\SimpleVkException) {
            self::addToAssertionCount(1);
        }
    }

    public function testErrorReporterCompactsAndNotifies(): void
    {
        $sentTo = [];
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $reporter = new ErrorReporter(
            logger: $logger,
            notifyVkId: 111,
            messageSender: static function (string $text, int $vkId) use (&$sentTo): void {
                $sentTo[$vkId][] = $text;
            },
            appTitle: 'TowerGame',
        )->shortTrace();

        $reporter->report(new \RuntimeException('boom'), 'обработка команды');

        // В лог попало компактное сообщение
        self::assertNotEmpty($logger->messages);
        self::assertStringContainsString('RuntimeException: boom', $logger->messages[0]);
        self::assertStringContainsString('DispatcherTest.php', $logger->messages[0]);

        // Уведомление ушло разработчику
        self::assertArrayHasKey(111, $sentTo);
        self::assertStringContainsString('TowerGame', $sentTo[111][0]);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function incomingMessage(
        string $text,
        ?string $payload = null,
    ): \DigitalStars\SimpleVK\Message\IncomingMessage {
        $config = ClientConfig::create('T', 9);
        $update = \DigitalStars\SimpleVK\Event\Update::fromLongPoll([
            'type' => 'message_new',
            'event_id' => 'e' . \mt_rand(),
            'group_id' => 9,
            'object' => [
                'message' => [
                    'id' => \mt_rand(),
                    'from_id' => 100500,
                    'peer_id' => 2000000099,
                    'text' => $text,
                    ...($payload !== null ? ['payload' => $payload] : []),
                ],
                'client_info' => [],
            ],
        ]);

        return new \DigitalStars\SimpleVK\Message\IncomingMessage(
            update: $update,
            api: new ApiClient(ClientConfig::create('T', 9), $this->fake),
            groupId: 9,
        );
    }
}
