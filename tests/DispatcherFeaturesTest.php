<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\Tests\tmp_actions\EchoMiddleware;
use DigitalStars\SimpleVK\Tests\tmp_actions\ExceptionHandlerAction;
use DigitalStars\SimpleVK\Tests\tmp_actions\GreetAction;
use DigitalStars\SimpleVK\Tests\tmp_actions\ScopedChildAction;
use DigitalStars\SimpleVK\Tests\tmp_throttled\LimitedAction;
use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Dispatcher\ArgumentResolver;
use DigitalStars\SimpleVK\Dispatcher\DispatcherConfig;
use DigitalStars\SimpleVK\Dispatcher\EventDispatcher;
use DigitalStars\SimpleVK\Message\IncomingMessage;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class DispatcherFeaturesTest extends TestCase
{
    private FakeTransport $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport(['messages.send' => [['message_id' => 1]]]);
        GreetAction::$lastName = null;
        ExceptionHandlerAction::$lastException = null;
        LimitedAction::$hits = 0;
        ScopedChildAction::$hits = 0;
        EchoMiddleware::$hits = 0;
    }

    public function testNamedCaptureGroupsInjectByName(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->handle($this->incoming('привет Иван'));

        self::assertSame('Иван', GreetAction::$lastName);
    }

    public function testOnExceptionRoutesToHandler(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->handle($this->incoming('/boom'));

        self::assertNotNull(ExceptionHandlerAction::$lastException);
        self::assertSame('взрыв', ExceptionHandlerAction::$lastException->getMessage());
    }

    public function testScopedMiddlewareInheritedFromBaseClass(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->handle($this->incoming('/scoped'));

        self::assertSame(1, ScopedChildAction::$hits);
        self::assertSame(1, EchoMiddleware::$hits); // middleware от базового класса применился
    }

    public function testThrottleLimitsPerUserAndAllowsOthers(): void
    {
        $cache = new ArrayCache();
        $dispatcher = $this->dispatcher(cache: $cache, dir: __DIR__ . '/tmp_throttled');

        $first = $this->incoming('/limited', userId: 777);
        $second = $this->incoming('/limited', userId: 777);
        $third = $this->incoming('/limited', userId: 777);
        $otherUser = $this->incoming('/limited', userId: 888);

        $dispatcher->handle($first);
        $dispatcher->handle($second);
        $dispatcher->handle($third); // лимит max=2 исчерпан

        self::assertSame(2, LimitedAction::$hits);

        $dispatcher->handle($otherUser); // другой пользователь — свой счётчик

        self::assertSame(3, LimitedAction::$hits);
    }

    public function testThrottleWithoutCacheFailsFastAtRegistration(): void
    {
        $this->expectException(\LogicException::class);

        // LimitedAction с #[Throttle] есть в директории, кэш не передан.
        $this->dispatcher(cache: null, dir: __DIR__ . '/tmp_throttled');
    }

    private function dispatcher(
        ?ArrayCache $cache = new ArrayCache(),
        string $dir = __DIR__ . '/tmp_actions',
    ): EventDispatcher {
        return new EventDispatcher(
            new DispatcherConfig([$dir]),
            new ArgumentResolver(),
            new ApiClient(ClientConfig::create('T', 9), $this->fake),
            $cache,
        );
    }

    private function incoming(string $text, ?int $userId = null): IncomingMessage
    {
        $update = \DigitalStars\SimpleVK\Event\Update::fromLongPoll([
            'type' => 'message_new',
            'event_id' => 'e' . \mt_rand(),
            'group_id' => 9,
            'object' => [
                'message' => [
                    'id' => \mt_rand(),
                    'from_id' => $userId ?? 100500,
                    'peer_id' => 2000000099,
                    'text' => $text,
                ],
                'client_info' => [],
            ],
        ]);

        return new IncomingMessage(
            update: $update,
            api: new ApiClient(ClientConfig::create('T', 9), $this->fake),
            groupId: 9,
        );
    }
}
