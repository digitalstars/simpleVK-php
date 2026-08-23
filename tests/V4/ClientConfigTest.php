<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Transport\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientConfigTest extends TestCase
{
    public function testCreateDefaults(): void
    {
        $config = ClientConfig::create('tok', 123);

        self::assertSame('tok', $config->token);
        self::assertSame(123, $config->groupId);
        self::assertSame('5.199', $config->apiVersion);
        self::assertNull($config->rateLimitPerSecond);
        self::assertNull($config->cache);
    }

    public function testWithersReturnNewInstanceAndKeepOriginal(): void
    {
        $original = ClientConfig::create('tok', 1);
        $modified = $original->withRetry(5, backoffMs: 100)->withRateLimit(10.0)->withConfirmationSecret('secret');

        self::assertNotSame($original, $modified);
        self::assertSame(3, $original->retryMaxAttempts); // оригинал не тронут
        self::assertNull($original->rateLimitPerSecond);
        self::assertNull($original->confirmationSecret);

        self::assertSame(5, $modified->retryMaxAttempts);
        self::assertSame(100, $modified->retryBackoffMs);
        self::assertSame(10.0, $modified->rateLimitPerSecond);
        self::assertSame('secret', $modified->confirmationSecret);
    }

    public function testFromEnvReadsVariables(): void
    {
        putenv('SIMPLEVK_TEST_TOKEN=tok123');
        putenv('SIMPLEVK_TEST_GROUP_ID=777');
        putenv('SIMPLEVK_TEST_API_VERSION=5.200');
        putenv('SIMPLEVK_TEST_CONFIRMATION_SECRET=abc');

        try {
            $config = ClientConfig::fromEnv('SIMPLEVK_TEST');

            self::assertSame('tok123', $config->token);
            self::assertSame(777, $config->groupId);
            self::assertSame('5.200', $config->apiVersion);
            self::assertSame('abc', $config->confirmationSecret);
        } finally {
            putenv('SIMPLEVK_TEST_TOKEN');
            putenv('SIMPLEVK_TEST_GROUP_ID');
            putenv('SIMPLEVK_TEST_API_VERSION');
            putenv('SIMPLEVK_TEST_CONFIRMATION_SECRET');
        }
    }

    public function testFromEnvFailsWithoutToken(): void
    {
        putenv('SIMPLEVK_MISSING_TOKEN');

        try {
            ClientConfig::fromEnv('SIMPLEVK_MISSING');
            self::fail('Ожидалось исключение из-за отсутствия токена');
        } catch (SimpleVkException $e) {
            self::assertStringContainsString('SIMPLEVK_MISSING_TOKEN', $e->getMessage());
        }
    }

    public function testEmptyTokenRejected(): void
    {
        $this->expectException(SimpleVkException::class);
        new ClientConfig(token: '', groupId: 1);
    }

    public function testGetTransportReturnsConfiguredOrDefault(): void
    {
        $fake = new FakeTransport();
        $config = ClientConfig::create('t', 1)->withTransport($fake);

        self::assertSame($fake, $config->getTransport());
        // Дефолт — CurlTransport
        $default = ClientConfig::create('t', 1)->getTransport();
        self::assertSame(\DigitalStars\SimpleVK\V4\Transport\CurlTransport::class, $default::class);
    }
}
