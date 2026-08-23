<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Event\UpdateType;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class FakeTransportTest extends TestCase
{
    public function testWrapsResponseAndRecordsCalls(): void
    {
        $fake = new FakeTransport(['groups.getById' => ['groups' => [['id' => 42]]]]);

        $result = $fake->call('groups.getById', ['group_id' => 42]);

        self::assertSame(['response' => ['groups' => [['id' => 42]]]], $result);
        self::assertCount(1, $fake->calls);
        self::assertSame(['group_id' => 42], $fake->lastParamsFor('groups.getById'));
    }

    public function testQueueOfResponses(): void
    {
        // Очередь последовательных ответов — только через явный setResponseQueue
        $fake = new FakeTransport();
        $fake->setResponseQueue('m', [['step' => 1], ['step' => 2]]);

        self::assertSame(['response' => ['step' => 1]], $fake->call('m'));
        self::assertSame(['response' => ['step' => 2]], $fake->call('m'));
    }

    public function testListOfArraysIsSingleListResponse(): void
    {
        // Список массивов в конструкторе — это ЦЕЛИКОМ значение response,
        // а не очередь (VK часто отвечает списками объектов).
        $fake = new FakeTransport(['users.get' => [['id' => 1], ['id' => 2]]]);

        self::assertSame(['response' => [['id' => 1], ['id' => 2]]], $fake->call('users.get'));
    }

    public function testScalarResponseIsSingle(): void
    {
        $fake = new FakeTransport(['ok' => true]);

        self::assertSame(['response' => true], $fake->call('ok'));
    }

    public function testMissingResponseThrows(): void
    {
        $fake = new FakeTransport();

        $this->expectException(SimpleVkException::class);
        $this->expectExceptionMessage('nope');
        $fake->call('nope');
    }
}

final class UpdateTest extends TestCase
{
    public function testFromLongPollMessageNew(): void
    {
        $raw = [
            'type' => 'message_new',
            'event_id' => 'eid1',
            'group_id' => 5,
            'object' => [
                'message' => ['id' => 10, 'text' => 'привет'],
                'client_info' => [],
            ],
        ];

        $update = Update::fromLongPoll($raw);

        self::assertSame(UpdateType::MessageNew, $update->type);
        self::assertSame(5, $update->groupId);
        self::assertSame('привет', $update->object()['message']['text']);
    }

    public function testUnknownTypeFallsBack(): void
    {
        $update = Update::fromLongPoll(['type' => 'brand_new_event']);

        self::assertSame(UpdateType::Unknown, $update->type);
    }
}
