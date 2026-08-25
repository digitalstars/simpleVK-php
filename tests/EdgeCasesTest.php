<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Async\AmpAdapter;
use DigitalStars\SimpleVK\Async\AmpTransport;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Exception\SimpleVkException;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Добивка покрытия: ветки ApiClient/ClientConfig/Bot и AmpTransport
 * против локального мок-сервера (php -S).
 */
final class EdgeCasesTest extends TestCase
{
    public function testCallAsyncRejectsNonAsyncTransport(): void
    {
        $api = new ApiClient(ClientConfig::create('T', 1), new FakeTransport());

        $this->expectException(SimpleVkException::class);
        $this->expectExceptionMessage('не поддерживает асинхронные');
        $api->callAsync('users.get');
    }

    public function testUnwrapThrowsOnNonArrayResponse(): void
    {
        $fake = new class implements \DigitalStars\SimpleVK\Transport\Transport {
            public function call(string $method, array $params = []): array
            {
                return ['response' => 'scalar'];
            }
        };
        $api = new ApiClient(ClientConfig::create('T', 1), $fake);

        $this->expectException(SimpleVkException::class);
        $this->expectExceptionMessage('не-массивный response');
        $api->call('x');
    }

    public function testConfigValidationRejectsBadValues(): void
    {
        $config = ClientConfig::create('T', 1);

        try {
            $config->withRetry(0);
            self::fail();
        } catch (SimpleVkException) {
            self::addToAssertionCount(1);
        }

        try {
            $config->withRateLimit(-5.0);
            self::fail();
        } catch (SimpleVkException) {
            self::addToAssertionCount(1);
        }
    }

    public function testUpdateObjectFallsBackToEmptyArray(): void
    {
        $update = \DigitalStars\SimpleVK\Event\Update::fromLongPoll([
            'type' => 'message_new',
            'object' => null,
        ]);

        self::assertSame([], $update->object());
    }

    /**
     * Интеграционный тест AmpTransport через локальный мок-сервер.
     *
     * @group integration
     */
    public function testAmpTransportAgainstLocalMockServer(): void
    {
        $router = \dirname(__DIR__, 1) . '/tests/tmp_server/vk_api.php';
        if (!\is_file($router)) {
            $this->markTestSkipped('Нет tmp_server');
        }

        $port = 8937;
        $process = \proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            $this->markTestSkipped('Не удалось запустить мок-сервер');
        }

        // Ждём готовности сервера
        $ready = false;
        for ($i = 0; $i < 40; ++$i) {
            $err = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($err !== false) {
                \fclose($err);
                $ready = true;

                break;
            }
            \usleep(100_000);
        }

        try {
            if (!$ready) {
                $this->markTestSkipped('Мок-сервер не поднялся');
            }

            // Роутер-режим: любой путь обрабатывается vk_api.php
            $transport = new AmpTransport(AmpAdapter::httpClient(), "http://127.0.0.1:{$port}/users.get");
            $api = new ApiClient(ClientConfig::create('TEST', 1), $transport);

            $result = $api->callAsync('users.get', ['user_ids' => '1'])->await();
            self::assertSame([['id' => 1, 'first_name' => 'Test']], $result);

            // Синхронный фасад поверх async
            $sync = $transport->call('users.get');
            self::assertArrayHasKey('response', $sync);
        } finally {
            \proc_terminate($process);
            \proc_close($process);
        }
    }
}
