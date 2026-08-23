<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Bot;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Message\IncomingMessage;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Тесты LongPoll-слоя: построение Update из ответов сервера и полный флоу
 * dispatch → onMessage → reply через FakeTransport.
 */
final class LongPollTest extends TestCase
{
    public function testUpdateFromLongPollShape(): void
    {
        $update = Update::fromLongPoll([
            'type' => 'message_new',
            'event_id' => 'x',
            'group_id' => 42,
            'object' => [
                'message' => ['id' => 1, 'from_id' => 7, 'peer_id' => 2000000001, 'text' => 'hi'],
                'client_info' => [],
            ],
        ]);

        self::assertSame('hi', $update->object()['message']['text']);
        self::assertSame(42, $update->groupId);
    }

    public function testBotHandlesFullMessageFlow(): void
    {
        $fake = new FakeTransport();
        $fake->setResponse('messages.send', [['message_id' => 55]]);
        $bot = Bot::create(ClientConfig::create('T', 9)->withTransport($fake));

        $got = null;
        $bot->onMessage(function (IncomingMessage $m) use (&$got): void {
            $got = $m;
            $m->outgoing()->text('ok')->send();
        });

        $bot->dispatch([
            'type' => 'message_new',
            'event_id' => '1',
            'group_id' => 9,
            'object' => [
                'message' => ['id' => 3, 'from_id' => 11, 'peer_id' => 2000000002, 'text' => 'тест'],
                'client_info' => [],
            ],
        ]);

        self::assertNotNull($got);
        self::assertSame('тест', $got->text());
        self::assertSame(2000000002, $fake->lastParamsFor('messages.send')['peer_id']);
    }

    public function testLongPollRequiresGroupId(): void
    {
        $fake = new FakeTransport();
        $bot = Bot::create(ClientConfig::create('T', 0)->withTransport($fake));

        $this->expectException(SimpleVkException::class);
        new \DigitalStars\SimpleVK\V4\LongPoll\LongPollClient($bot->config, $bot->api());
    }
}
