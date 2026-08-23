<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    public function testCallAddsTokenAndVersionAndReturnsResponse(): void
    {
        $fake = new FakeTransport(['users.get' => [['id' => 1, 'first_name' => 'Пётр']]]);
        $client = new ApiClient(ClientConfig::create('TOKEN', 42), $fake);

        $result = $client->call('users.get', ['user_ids' => '1']);

        self::assertSame([['id' => 1, 'first_name' => 'Пётр']], $result);
        $params = $fake->lastParamsFor('users.get');
        self::assertSame('TOKEN', $params['access_token']);
        self::assertSame('5.199', $params['v']);
    }

    public function testNestedParamsAreJsonEncoded(): void
    {
        $fake = new FakeTransport(['messages.send' => ['message_id' => 7]]);
        $client = new ApiClient(ClientConfig::create('T', 1), $fake);

        $keyboard = ['buttons' => [[['action' => ['type' => 'text']]]]];
        $client->call('messages.send', ['user_id' => 5, 'keyboard' => $keyboard]);

        $sent = $fake->lastParamsFor('messages.send')['keyboard'];
        self::assertIsString($sent);
        self::assertSame($keyboard, json_decode($sent, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testVkErrorThrowsWithCode(): void
    {
        $transport = new class implements \DigitalStars\SimpleVK\V4\Transport\Transport {
            public int $hits = 0;

            public function call(string $method, array $params = []): array
            {
                ++$this->hits;

                return ['error' => ['error_code' => 15, 'error_msg' => 'Access denied']];
            }
        };
        $client = new ApiClient(ClientConfig::create('T', 1)->withRetry(3, backoffMs: 0), $transport);

        try {
            $client->call('wall.post');
            self::fail('Ожидался SimpleVkException');
        } catch (SimpleVkException $e) {
            self::assertSame(15, $e->vkErrorCode);
            self::assertStringContainsString('wall.post', $e->getMessage());
        }
    }

    public function testRetryableErrorRetriesThenSucceeds(): void
    {
        $transport = new class implements \DigitalStars\SimpleVK\V4\Transport\Transport {
            public int $hits = 0;

            public function call(string $method, array $params = []): array
            {
                ++$this->hits;
                if ($this->hits === 1) {
                    return ['error' => ['error_code' => 6, 'error_msg' => 'Too many requests per second']];
                }

                return ['response' => ['ok' => true]];
            }
        };
        $client = new ApiClient(ClientConfig::create('T', 1)->withRetry(3, backoffMs: 0), $transport);

        self::assertSame(['ok' => true], $client->call('status.set'));
        self::assertSame(2, $transport->hits);
    }

    public function testRetryExhaustionThrows(): void
    {
        $transport = new class implements \DigitalStars\SimpleVK\V4\Transport\Transport {
            public int $hits = 0;

            public function call(string $method, array $params = []): array
            {
                ++$this->hits;

                return ['error' => ['error_code' => 6, 'error_msg' => 'flood']];
            }
        };
        $client = new ApiClient(ClientConfig::create('T', 1)->withRetry(3, backoffMs: 0), $transport);

        $thrown = false;
        try {
            $client->call('x');
        } catch (SimpleVkException) {
            $thrown = true;
        }
        self::assertTrue($thrown);
        self::assertSame(3, $transport->hits);
    }

    public function testNonRetryableErrorDoesNotRetry(): void
    {
        $transport = new class implements \DigitalStars\SimpleVK\V4\Transport\Transport {
            public int $hits = 0;

            public function call(string $method, array $params = []): array
            {
                ++$this->hits;

                return ['error' => ['error_code' => 100, 'error_msg' => 'bad param']];
            }
        };
        $client = new ApiClient(ClientConfig::create('T', 1), $transport);

        try {
            $client->call('y');
        } catch (SimpleVkException) {
        } finally {
            self::assertSame(1, $transport->hits);
        }
    }

    public function testRateLimitThrottlesRequests(): void
    {
        $fake = new FakeTransport([
            'a' => [true],
            'b' => [true],
        ]);
        $client = new ApiClient(ClientConfig::create('T', 1)->withRateLimit(50.0), $fake);

        $start = hrtime(true);
        $client->call('a');
        $client->call('b');
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        // При лимите 50 rps минимальный интервал между запросами = 20 мс.
        self::assertGreaterThanOrEqual(19.0, $elapsedMs);
    }
}
