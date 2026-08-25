<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Auth\OAuth;
use DigitalStars\SimpleVK\Bot;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Event\UpdateType;
use DigitalStars\SimpleVK\Exception\SimpleVkException;
use DigitalStars\SimpleVK\LongPoll\LongPollClient;
use DigitalStars\SimpleVK\Message\Carousel;
use DigitalStars\SimpleVK\Message\IncomingMessage;
use DigitalStars\SimpleVK\Message\OutgoingMessage;
use DigitalStars\SimpleVK\Transport\CurlTransport;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    // ---------- IncomingMessage ----------

    private function dispatchWith(array $message): IncomingMessage
    {
        $fake = new FakeTransport();
        $bot = Bot::create(ClientConfig::create('T', 77)->withTransport($fake));
        $captured = null;
        $bot->onMessage(static function (IncomingMessage $m) use (&$captured): void {
            $captured = $m;
        });
        $bot->dispatch([
            'type' => 'message_new',
            'group_id' => 77,
            'object' => ['message' => $message, 'client_info' => []],
        ]);
        \assert($captured instanceof IncomingMessage);

        return $captured;
    }

    public function testIncomingMessageHelpers(): void
    {
        $msg = $this->dispatchWith([
            'id' => 11,
            'from_id' => 100,
            'peer_id' => 2000000010,
            'text' => 'привет',
            'attachments' => [
                ['type' => 'photo', 'photo' => ['owner_id' => -7, 'id' => 42, 'access_key' => 'abc']],
                ['type' => 'sticker', 'sticker' => ['owner_id' => 100, 'id' => 5]],
                ['type' => 'broken'],
            ],
        ]);

        self::assertSame(11, $msg->messageId());
        self::assertSame(100, $msg->senderId());
        self::assertTrue($msg->isFromUser());
        self::assertFalse($msg->isFromGroup());
        self::assertFalse($msg->isPrivate());
        self::assertTrue($msg->hasText());

        $attachments = $msg->attachments();
        self::assertCount(2, $attachments);
        self::assertSame(['type' => 'photo', 'id' => '-7_42_abc'], $attachments[0]);
        self::assertSame(['100_5'], $msg->attachmentsByType('sticker'));
        self::assertSame([], $msg->attachmentsByType('audio'));
    }

    public function testIncomingGroupSenderAndPrivateChat(): void
    {
        $group = $this->dispatchWith(['id' => 1, 'from_id' => -99, 'peer_id' => -99]);
        self::assertTrue($group->isFromGroup());
        self::assertFalse($group->isFromUser());

        $private = $this->dispatchWith(['id' => 2, 'from_id' => 55, 'peer_id' => 55]);
        self::assertTrue($private->isPrivate(), 'peer == from → личный диалог');
    }

    public function testPayloadInvalidJsonReturnsNull(): void
    {
        $msg = $this->dispatchWith(['id' => 1, 'from_id' => 2, 'peer_id' => 3, 'text' => '', 'payload' => '{broken']);
        self::assertNull($msg->payload());

        $noPayload = $this->dispatchWith(['id' => 1, 'from_id' => 2, 'peer_id' => 3, 'text' => 'x']);
        self::assertNull($noPayload->payload());
    }

    // ---------- OutgoingMessage extra paths ----------

    public function testSendWithAllOptionalParameters(): void
    {
        $fake = new FakeTransport(['messages.send' => [['message_id' => 3]]]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        new OutgoingMessage($api)
            ->toUser(500)
            ->text('с параметрами')
            ->forward([10, 20])
            ->replyTo(9)
            ->location(55.75, 37.61)
            ->dontParseLinks()
            ->disableMentions()
            ->send();

        $p = $fake->lastParamsFor('messages.send');
        self::assertSame(500, $p['user_id']);
        self::assertSame('10,20', $p['message_ids']);
        self::assertSame(9, $p['reply_to']);
        self::assertSame(55.75, $p['lat']);
        self::assertSame(37.61, $p['long']);
        self::assertSame(1, $p['dont_parse_links']);
        self::assertSame(1, $p['disable_mentions']);
    }

    public function testEmptyMessageThrows(): void
    {
        $api = new ApiClient(ClientConfig::create('T', 1), new FakeTransport());
        $msg = new OutgoingMessage($api)->to(1);

        $this->expectException(SimpleVkException::class);
        $msg->send();
    }

    public function testStickerOnlyMessage(): void
    {
        $fake = new FakeTransport(['messages.send' => [['message_id' => 8]]]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        new OutgoingMessage($api)
            ->to(1)
            ->sticker(100)
            ->send();

        self::assertSame(100, $fake->lastParamsFor('messages.send')['sticker_id']);
    }

    public function testCarouselSentAsTemplate(): void
    {
        $fake = new FakeTransport(['messages.send' => [['message_id' => 4]]]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        $carousel = Carousel::make()->element(title: 'A');
        new OutgoingMessage($api)
            ->to(1)
            ->text('каталог')
            ->carousel($carousel)
            ->send();

        $template = $fake->lastParamsFor('messages.send')['template'];
        self::assertIsString($template);
        self::assertSame('carousel', json_decode($template, true)['type']);
    }

    public function testSplitHardBoundaryWithoutSeparators(): void
    {
        $chunks = OutgoingMessage::splitText(\str_repeat('а', OutgoingMessage::MAX_LENGTH + 150));

        self::assertCount(2, $chunks);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(OutgoingMessage::MAX_LENGTH, \mb_strlen($chunk));
        }
    }

    // ---------- LongPollClient via httpGet seam ----------

    private function longPollWithResponses(array $responses): LongPollClient
    {
        return new class($responses) extends LongPollClient {
            /** @var list<array<string, mixed>> */
            private array $queue;

            public function __construct(array $queue)
            {
                parent::__construct(ClientConfig::create('T', 42), new ApiClient(
                    ClientConfig::create('T', 42),
                    new FakeTransport([
                        'groups.getLongPollServer' => ['key' => 'k', 'server' => 'https://lp', 'ts' => 1],
                    ]),
                ));
                $this->queue = $queue;
            }

            protected function httpGet(string $url): string|false
            {
                return $this->queue === [] ? false : \json_encode(\array_shift($this->queue));
            }
        };
    }

    public function testLongPollSuccessPath(): void
    {
        $client = $this->longPollWithResponses([[
            'ts' => 101,
            'updates' => [
                ['type' => 'message_new', 'event_id' => 'e1', 'group_id' => 42, 'object' => []],
                ['type' => 'wall_post_new', 'event_id' => 'e2', 'group_id' => 42, 'object' => []],
            ],
        ]]);

        $updates = $client->wait();

        self::assertCount(2, $updates);
        self::assertSame(UpdateType::MessageNew, $updates[0]->type);
        self::assertSame(UpdateType::WallPostNew, $updates[1]->type);
    }

    public function testLongPollFailedOneAdvancesTs(): void
    {
        $client = $this->longPollWithResponses([
            ['failed' => 1, 'ts' => 55],
            ['ts' => 56, 'updates' => [['type' => 'group_join', 'group_id' => 42]]],
        ]);

        // failed=1 → первый wait() пуст (сессия пересоздаётся), второй читает следующее событие
        self::assertSame([], $client->wait());
        $second = $client->wait();
        self::assertCount(1, $second);
        self::assertSame(UpdateType::GroupJoin, $second[0]->type);
    }

    public function testLongPollFailedTwoResetsSession(): void
    {
        $client = $this->longPollWithResponses([['failed' => 2]]);
        self::assertSame([], $client->wait()); // сброс сессии без исключения
    }

    public function testLongPollNetworkFailureThrows(): void
    {
        $client = new class extends LongPollClient {
            public function __construct()
            {
                parent::__construct(ClientConfig::create('T', 1), new ApiClient(
                    ClientConfig::create('T', 1),
                    new FakeTransport([
                        'groups.getLongPollServer' => ['key' => 'k', 'server' => 'https://lp', 'ts' => 1],
                    ]),
                ));
            }

            protected function httpGet(string $url): string|false
            {
                return false;
            }
        };

        $this->expectException(SimpleVkException::class);
        $this->expectExceptionMessage('таймаут');
        $client->wait();
    }

    // ---------- OAuth ----------

    public function testOAuthAuthUrl(): void
    {
        $oauth = new OAuth(123456, 'secret', 'https://bot.example/cb', ['messages', 'groups']);

        $url = $oauth->authUrl('csrf-token-1');
        $query = [];
        \parse_str((string) \parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('123456', $query['client_id']);
        self::assertSame('messages groups', $query['scope']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('csrf-token-1', $query['state']);
    }

    public function testOAuthFetchTokenParsesResponse(): void
    {
        $oauth = new class(1, 's', 'r') extends OAuth {
            protected function httpGet(string $url): string|false
            {
                return '{"access_token":"tok","user_id":7,"expires_in":3600}';
            }
        };

        $result = $oauth->fetchToken('code');

        self::assertSame('tok', $result['access_token']);
        self::assertSame(7, $result['user_id']);
    }

    public function testOAuthFetchTokenError(): void
    {
        $oauth = new class(1, 's', 'r') extends OAuth {
            protected function httpGet(string $url): string|false
            {
                return '{"error":"invalid_client","error_description":"bad secret"}';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad secret');
        $oauth->fetchToken('c');
    }

    // ---------- CurlTransport decode ----------

    public function testCurlDecodePaths(): void
    {
        self::assertSame(['a' => 1], CurlTransport::decode('{"a":1}', 'm'));

        try {
            CurlTransport::decode('not-json{', 'm');
            self::fail();
        } catch (SimpleVkException $e) {
            self::assertSame(SimpleVkException::TRANSPORT_ERROR, $e->vkErrorCode);
        }

        try {
            CurlTransport::decode('"just a string"', 'm');
            self::fail();
        } catch (SimpleVkException) {
            self::addToAssertionCount(1);
        }
    }

    // ---------- Bot typed handlers ----------

    public function testBotTypedHandlerReceivesUpdateAndFallbackOrder(): void
    {
        $fake = new FakeTransport();
        $bot = Bot::create(ClientConfig::create('T', 1)->withTransport($fake));
        $hits = [];

        $bot->on('group_leave', static function () use (&$hits): void {
            $hits[] = 'leave';
        });
        $bot->onCallback(static function () use (&$hits): void {
            $hits[] = 'callback';
        });

        $bot->dispatch(['type' => 'group_leave', 'group_id' => 1, 'object' => []]);

        // callback-кнопка
        $bot->dispatch(['type' => 'message_event', 'group_id' => 1, 'object' => ['payload' => '{"a":1}']]);

        self::assertSame(['leave', 'callback'], $hits);
    }
}
