<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Message\CallbackAnswer;
use DigitalStars\SimpleVK\Service\Mentions;
use DigitalStars\SimpleVK\Service\UserInfo;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    public function testIteratePaginatesUntilShortPage(): void
    {
        // Страница 1 полная (2 элемента), страница 2 короткая (1) → стоп
        $fake = new FakeTransport();
        $fake->setResponseQueue('groups.getMembers', [
            ['items' => [['id' => 1], ['id' => 2]], 'count' => 3],
            ['items' => [['id' => 3]], 'count' => 3],
        ]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        $ids = [];
        foreach ($api->iterate('groups.getMembers', ['group_id' => 5], 'items', 2) as $item) {
            $ids[] = $item['id'];
        }

        self::assertSame([1, 2, 3], $ids);
        self::assertCount(2, $fake->calls);

        $secondParams = $fake->calls[1]['params'];
        self::assertSame(2, $secondParams['offset']);
    }

    public function testIterateCursorPagination(): void
    {
        $fake = new FakeTransport();
        $fake->setResponseQueue('newsfeed.search', [
            ['items' => [['x' => 1]], 'next_from' => 'cursor_a'],
            ['items' => [['x' => 2]]],
        ]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        $seen = [];
        foreach ($api->iterate('newsfeed.search') as $item) {
            $seen[] = $item['x'];
        }

        self::assertSame([1, 2], $seen);
        self::assertSame('cursor_a', $fake->calls[1]['params']['start_from'] ?? null);
    }

    public function testMentionsFormatting(): void
    {
        self::assertSame('[id123|Пётр]', Mentions::user(123, 'Пётр'));
        self::assertSame('[club456|Группа]', Mentions::group(-456, 'Группа'));
        self::assertSame('[club456|Группа]', Mentions::group(456, 'Группа'));

        $text = Mentions::replaceScreenNames('@petr привет, пиши @support', [
            '@petr' => '[id1|Пётр]',
            '@support' => '[club2|Поддержка]',
        ]);

        self::assertSame('[id1|Пётр] привет, пиши [club2|Поддержка]', $text);
    }

    public function testUserInfoHelpers(): void
    {
        $fake = new FakeTransport([
            'users.get' => [['first_name' => 'Анна']],
            'groups.isMember' => ['member' => 1],
        ]);
        $info = new UserInfo(new ApiClient(ClientConfig::create('T', 9), $fake), 9);

        $profile = $info->info(42);
        self::assertSame('Анна', $profile['first_name']);

        self::assertTrue($info->isAdmin(42));
        self::assertTrue($info->isMember(42));

        self::assertFalse(UserInfo::supports([], 'keyboard'));
        self::assertTrue(UserInfo::supports(['keyboard' => true], 'keyboard'));
    }

    public function testUserInfoIsAdminSwallowsApiError(): void
    {
        $failing = new class implements \DigitalStars\SimpleVK\Transport\Transport {
            public function call(string $method, array $params = []): array
            {
                throw new \DigitalStars\SimpleVK\Exception\SimpleVkException(15, 'denied');
            }
        };
        $info = new UserInfo(new ApiClient(ClientConfig::create('T', 9), $failing), 9);

        self::assertFalse($info->isAdmin(7));
        self::assertFalse($info->isMember(7));
    }

    public function testCallbackAnswerSendsEventData(): void
    {
        $fake = new FakeTransport(['messages.sendMessageEventAnswer' => [true]]);
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        CallbackAnswer::for($api, 'evt-1', 500)->snackbar('Принято');
        CallbackAnswer::for($api, 'evt-1', 500)->openLink('https://x.ru');
        CallbackAnswer::for($api, 'evt-1', 500)->openApp(6123456, -99, 'hash');
        CallbackAnswer::for($api, 'evt-1', 500)->empty();

        $calls = array_values(array_filter(
            $fake->calls,
            static fn($c) => $c['method'] === 'messages.sendMessageEventAnswer',
        ));

        $snack = json_decode($calls[0]['params']['event_data'], true);
        self::assertSame(['type' => 'show_snackbar', 'text' => 'Принято'], $snack);

        $link = json_decode($calls[1]['params']['event_data'], true);
        self::assertSame(['type' => 'open_link', 'link' => 'https://x.ru'], $link);

        $app = json_decode($calls[2]['params']['event_data'], true);
        self::assertSame(6123456, $app['app_id']);
        self::assertSame(-99, $app['owner_id']);

        // empty(): без event_data
        self::assertArrayNotHasKey('event_data', $calls[3]['params']);
    }
}
