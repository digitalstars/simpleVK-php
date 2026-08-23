<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Dto\ChatDto;
use DigitalStars\SimpleVK\V4\Dto\MessageDto;
use DigitalStars\SimpleVK\V4\Dto\UserDto;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function testUserDtoFromArrayAndHelpers(): void
    {
        $user = UserDto::fromArray([
            'id' => 123,
            'first_name' => 'Пётр',
            'last_name' => 'Иванов',
            'screen_name' => 'petr',
            'unknown_field' => 'ignored',
        ]);

        self::assertSame(123, $user->id);
        self::assertSame('Пётр', $user->firstName);
        self::assertSame('Иванов', $user->lastName);
        self::assertSame('petr', $user->screenName);
        self::assertSame('Пётр Иванов', $user->fullName());
        self::assertSame('[id123|Пётр]', $user->mention());
    }

    public function testUserDtoMentionFallsBackToScreenNameThenId(): void
    {
        self::assertSame('[id1|petr]', UserDto::fromArray(['id' => 1, 'screen_name' => 'petr'])->mention());
        self::assertSame('[id0|0]', UserDto::fromArray([])->mention()); // пустой массив → id 0
    }

    public function testChatDtoFromPeerId(): void
    {
        $user = ChatDto::fromPeerId(100);
        self::assertSame('user', $user->type);
        self::assertSame(100, $user->entityId());
        self::assertSame('Пользователь 100', $user->displayName());

        $group = ChatDto::fromPeerId(-42);
        self::assertSame('group', $group->type);
        self::assertSame(42, $group->entityId());

        $chat = ChatDto::fromPeerId(2000000005);
        self::assertSame('chat', $chat->type);
        self::assertSame(5, $chat->entityId());
    }

    public function testMessageDtoParsesAllFields(): void
    {
        $dto = MessageDto::fromArray([
            'id' => 9,
            'from_id' => 100,
            'peer_id' => 2000000010,
            'text' => 'привет',
            'payload' => '{"action":"buy","item":5}',
            'reply_message' => ['id' => 7],
            'attachments' => [
                ['type' => 'photo', 'photo' => ['owner_id' => -3, 'id' => 8, 'access_key' => 'k']],
                ['type' => 'broken'],
            ],
        ]);

        self::assertSame(9, $dto->id);
        self::assertNotNull($dto->from);
        self::assertSame(100, $dto->from->id);
        self::assertSame('привет', $dto->effectiveText());
        self::assertSame(['action' => 'buy', 'item' => 5], $dto->payload);
        self::assertTrue($dto->isReply());
        self::assertSame(7, $dto->replyToMessageId);
        self::assertSame([['type' => 'photo', 'id' => '-3_8_k']], $dto->attachments);
    }

    public function testMessageDtoCaptionAsEffectiveText(): void
    {
        $dto = MessageDto::fromArray([
            'id' => 2,
            'text' => '',
            'caption' => 'подпись фото',
            'attachments' => [],
        ]);

        self::assertSame('подпись фото', $dto->effectiveText());
        self::assertFalse($dto->isReply());
        self::assertNull($dto->payload);
    }
}
