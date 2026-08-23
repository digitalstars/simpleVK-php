<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Bot;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Message\Button;
use DigitalStars\SimpleVK\V4\Message\Carousel;
use DigitalStars\SimpleVK\V4\Message\Keyboard;
use DigitalStars\SimpleVK\V4\Message\OutgoingMessage;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private function makeBot(): array
    {
        $fake = new FakeTransport(['messages.send' => [['message_id' => 1]]]);

        return [Bot::create(ClientConfig::create('T', 5)->withTransport($fake)), $fake];
    }

    public function testKeyboardStructure(): void
    {
        $kb = Keyboard::make()
            ->row(Button::text('Да', ['a' => 1])->color(Button::COLOR_POSITIVE))
            ->row(Button::callback('Действие', 'raw_payload'), Button::link('Сайт', 'https://x.ru'))
            ->inline();

        $array = $kb->toArray();

        self::assertCount(2, $array['buttons']);
        self::assertTrue($array['inline']);
        self::assertFalse($array['one_time']);

        $textButton = $array['buttons'][0][0];
        self::assertSame('positive', $textButton['color']);
        self::assertSame(['a' => 1], json_decode($textButton['action']['payload'], true));
    }

    public function testCarouselStructure(): void
    {
        $carousel = Carousel::make()->element(
            title: 'Товар',
            description: 'Описание',
            photoId: 'photo-1_2',
            buttons: [Button::text('Купить')],
        );

        self::assertSame('carousel', $carousel->toArray()['type']);
        self::assertCount(1, $carousel->toArray()['elements']);
        self::assertSame('open_photo', $carousel->toArray()['elements'][0]['action']['type']);
    }

    public function testSendEncodesKeyboardAndAttachments(): void
    {
        [$bot, $fake] = $this->makeBot();
        $msg = new OutgoingMessage($bot->api());

        $msg
            ->to(2000000001)
            ->text('Привет')
            ->attachment('photo123_456')
            ->keyboard(Keyboard::make()->row(Button::text('Кнопка')))
            ->send();

        $params = $fake->lastParamsFor('messages.send');
        self::assertSame(2000000001, $params['peer_id']);
        self::assertSame('photo123_456', $params['attachment']);
        self::assertIsString($params['keyboard']);
        self::assertArrayHasKey('random_id', $params);
        self::assertArrayHasKey('v', $params);
        self::assertArrayHasKey('access_token', $params);
    }

    public function testLongTextIsSplitWithKeyboardInLastChunk(): void
    {
        [$bot, $fake] = $this->makeBot();
        $msg = new OutgoingMessage($bot->api());

        $longText = \str_repeat("абзац\n\n", 1500); // ~9000 символов
        $msg
            ->to(1)
            ->text($longText)
            ->keyboard(Keyboard::make()->row(Button::text('ok')))
            ->send();

        self::assertGreaterThanOrEqual(2, \count($fake->calls), 'Длинный текст разбивается на части');
        foreach ($fake->calls as $call) {
            self::assertLessThanOrEqual(
                OutgoingMessage::MAX_LENGTH + 10,
                \mb_strlen((string) $call['params']['message']),
            );
        }
    }

    public function testSendWithoutTargetThrows(): void
    {
        [$bot] = $this->makeBot();
        $msg = new OutgoingMessage($bot->api());

        $this->expectException(\DigitalStars\SimpleVK\V4\Exception\SimpleVkException::class);
        $msg->text('без адресата')->send();
    }

    public function testDispatchRoutesMessageNew(): void
    {
        [$bot, $fake] = $this->makeBot();
        $receivedText = null;

        $bot->onMessage(function ($incoming) use (&$receivedText) {
            $receivedText = $incoming->text();
            $incoming->reply('эхо')->send();
        });

        $bot->dispatch([
            'type' => 'message_new',
            'group_id' => 5,
            'object' => [
                'message' => ['id' => 9, 'from_id' => 100, 'peer_id' => 2000000001, 'text' => '/start'],
                'client_info' => [],
            ],
        ]);

        self::assertSame('/start', $receivedText);

        $params = $fake->lastParamsFor('messages.send');
        self::assertSame(2000000001, $params['peer_id']);
        self::assertSame('эхо', $params['message']);
        self::assertSame(9, $params['reply_to']);
    }

    public function testMiddlewareOrderingAndFallback(): void
    {
        [$bot] = $this->makeBot();
        $order = [];

        $bot->middleware(function ($update, $next) use (&$order) {
            $order[] = 'm1';
            $next($update);
        });
        $bot->middleware(function ($update, $next) use (&$order) {
            $order[] = 'm2';
            $next($update);
        });
        $bot->onFallback(function ($update) use (&$order) {
            $order[] = 'fallback:' . $update->type->value;
        });

        $bot->dispatch(['type' => 'group_join', 'group_id' => 5, 'event_id' => 'e', 'object' => []]);

        self::assertSame(['m1', 'm2', 'fallback:group_join'], $order);
    }

    public function testPayloadParsingFromIncomingMessage(): void
    {
        [$bot] = $this->makeBot();
        $capturedPayload = null;

        $bot->onMessage(function ($incoming) use (&$capturedPayload) {
            $capturedPayload = $incoming->payload();
        });

        $bot->dispatch([
            'type' => 'message_new',
            'group_id' => 5,
            'object' => [
                'message' => [
                    'id' => 1,
                    'from_id' => 7,
                    'peer_id' => 7,
                    'text' => '',
                    'payload' => '{"action":"help","num":3}',
                ],
                'client_info' => [],
            ],
        ]);

        self::assertSame(['action' => 'help', 'num' => 3], $capturedPayload);
    }
}
