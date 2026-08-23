<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Bot;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Dispatcher\Context;
use DigitalStars\SimpleVK\V4\Message\IncomingMessage;
use DigitalStars\SimpleVK\V4\Message\OutgoingMessage;
use DigitalStars\SimpleVK\V4\Service\Mentions;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Тесты внутренностей диспетчера: ArgumentResolver, Context, ErrorReporter.
 */
final class DispatcherInternalsTest extends TestCase
{
    private function makeContext(): Context
    {
        $resolver = new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver();
        $config = ClientConfig::create('T', 1);

        return new Context(
            api: new \DigitalStars\SimpleVK\V4\ApiClient($config, new FakeTransport()),
            dispatcher: new \DigitalStars\SimpleVK\V4\Dispatcher\EventDispatcher(
                new \DigitalStars\SimpleVK\V4\Dispatcher\DispatcherConfig([__DIR__ . '/tmp_actions']),
                $resolver,
            ),
            argumentResolver: $resolver,
            event: [],
            userId: 7,
            peerId: 8,
            messageText: 'txt',
        );
    }

    // ---------- ArgumentResolver приоритеты ----------

    public function testArgumentResolverPriorityOrder(): void
    {
        $resolver = new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver();
        $context = $this->makeContext();

        // Приоритет 1: тип Context; Приоритет 5: значение по умолчанию
        $method = new \ReflectionMethod($this, 'methodWithDefaults');

        $args = $resolver->getArguments($method, $context);
        self::assertSame($context, $args[0]);
        self::assertNull($args[1]);

        // Приоритет 2: именованный аргумент из payload
        $args = $resolver->getArguments($method, $context, ['extra' => 'X']);
        self::assertSame('X', $args[1]);
    }

    private function methodWithDefaults(Context $ctx, ?string $extra = null): void {}

    public function testArgumentResolverThrowsWhenNothingMatches(): void
    {
        $resolver = new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver();
        $context = $this->makeContext();

        $method = new \ReflectionMethod($this, 'methodWithRequired');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('required');
        $resolver->getArguments($method, $context);
    }

    private function methodWithRequired(string $required): void {}

    // ---------- Context ----------

    public function testContextMsgTargetsPeerAndAttributes(): void
    {
        $context = $this->makeContext();

        $outgoing = $context->msg('привет');
        self::assertInstanceOf(OutgoingMessage::class, $outgoing);

        $context->setAttribute('user', 'ann');
        self::assertSame('ann', $context->getAttribute('user'));
        self::assertNull($context->getAttribute('missing', null));
    }

    public function testContextGetSelfAndMissingFactory(): void
    {
        $context = $this->makeContext();

        self::assertSame($context, $context->get(Context::class));

        $noFactory = new \DigitalStars\SimpleVK\V4\Dispatcher\Context(
            api: new \DigitalStars\SimpleVK\V4\ApiClient(ClientConfig::create('T', 1), new FakeTransport()),
            dispatcher: $this->makeDispatcher(),
            argumentResolver: new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver(),
            event: [],
        );

        $this->expectException(\RuntimeException::class);
        $noFactory->get(\DateTimeImmutable::class);
    }

    private function makeDispatcher(): \DigitalStars\SimpleVK\V4\Dispatcher\EventDispatcher
    {
        return new \DigitalStars\SimpleVK\V4\Dispatcher\EventDispatcher(
            new \DigitalStars\SimpleVK\V4\Dispatcher\DispatcherConfig([__DIR__ . '/tmp_actions']),
            new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver(),
        );
    }

    // ---------- Mentions (перенос из ServicesTest для покрытия) ----------

    public function testMentionsSmoke(): void
    {
        self::assertSame('[id1|X]', Mentions::user(1, 'X'));
    }

    // ---------- ErrorReporter: middleware и фильтр путей ----------

    public function testErrorReporterMiddlewareCatchesThrowable(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $reporter = new \DigitalStars\SimpleVK\V4\Service\ErrorReporter($logger)->withTracePathFilter(
            'DispatcherInternalsTest',
        );

        $wrapped = $reporter->middleware();
        $handler = $wrapped(static function (): void {
            throw new \DomainException('inside handler');
        });

        // Не бросает: исключение превращено в лог
        $handler();

        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('DomainException: inside handler', $logger->messages[0]);
        self::assertStringContainsString('DispatcherInternalsTest.php', $logger->messages[0]);
    }

    // ---------- EventDispatcher: callback-события и карта маршрутов ----------

    public function testCallbackEventRoutingAndContextApi(): void
    {
        $fake = new FakeTransport([
            'messages.sendMessageEventAnswer' => [true],
            'messages.send' => [['message_id' => 2]],
        ]);
        $api = new ApiClient(ClientConfig::create('T', 9), $fake);

        $dispatcher = new \DigitalStars\SimpleVK\V4\Dispatcher\EventDispatcher(
            new \DigitalStars\SimpleVK\V4\Dispatcher\DispatcherConfig([__DIR__ . '/tmp_actions']),
            new \DigitalStars\SimpleVK\V4\Dispatcher\ArgumentResolver(),
            $api,
        );

        // Callback-кнопка (message_event) с payload action=PingBtn
        $dispatcher->handle([
            'type' => 'message_event',
            'event_id' => 'cb1',
            'group_id' => 9,
            'object' => [
                'user_id' => 777,
                'peer_id' => 2000000099,
                'payload' => '{"action":"PingBtn"}',
            ],
        ]);

        self::assertSame(1, \DigitalStars\SimpleVK\Tests\V4\tmp_actions\PingBtn::$hits);
        $params = $fake->lastParamsFor('messages.send');
        self::assertSame(2000000099, $params['peer_id']);
    }

    public function testDebugMapContainsRoutes(): void
    {
        $debug = $this->makeDispatcher()->debug();

        self::assertSame(
            \DigitalStars\SimpleVK\Tests\V4\tmp_actions\StartAction::class,
            $debug['routes']['command']['/start'],
        );
        self::assertSame(
            \DigitalStars\SimpleVK\Tests\V4\tmp_actions\PingBtn::class,
            $debug['routes']['payload']['PingBtn'],
        );
        self::assertSame(
            \DigitalStars\SimpleVK\Tests\V4\tmp_actions\CatchAllAction::class,
            $debug['routes']['fallback'],
        );
    }
}
