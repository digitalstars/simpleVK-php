<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Message\Button;
use DigitalStars\SimpleVK\V4\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;

final class ExtraCoverageTest extends TestCase
{
    /**
     * @group integration
     */
    public function testCurlTransportAgainstLocalMockServer(): void
    {
        $router = \dirname(__DIR__, 2) . '/tests/tmp_server/vk_api.php';
        if (!\is_file($router)) {
            $this->markTestSkipped('Нет tmp_server');
        }

        $port = 8943;
        $process = \proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $ready = false;
        for ($i = 0; $i < 40; ++$i) {
            $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                \fclose($sock);
                $ready = true;
                break;
            }
            \usleep(100_000);
        }

        try {
            if (!$ready) {
                $this->markTestSkipped('Мок-сервер не поднялся');
            }

            $transport = new CurlTransport("http://127.0.0.1:{$port}");
            $result = $transport->call('users.get', ['user_ids' => '1']);

            self::assertSame([['id' => 1, 'first_name' => 'Test']], $result['response']);
        } finally {
            \proc_terminate($process);
            \proc_close($process);
        }
    }

    public function testButtonFactoriesProduceCorrectActions(): void
    {
        $pay = Button::vkPay('action=transfer', 'hash1')->toArray();
        self::assertSame('vkpay', $pay['action']['type']);
        self::assertSame('hash1', $pay['action']['hash']);

        $app = Button::openApp(6123456, -99)->toArray();
        self::assertSame('open_app', $app['action']['type']);
        self::assertSame(6123456, $app['action']['app_id']);

        $loc = Button::location('{"geo":true}')->toArray();
        self::assertSame('location', $loc['action']['type']);

        // Цвет применяется только к text/callback
        $link = Button::link('L', 'https://x')->color(Button::COLOR_NEGATIVE)->toArray();
        self::assertArrayNotHasKey('color', $link);
    }
}
