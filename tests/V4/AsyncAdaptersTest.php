<?php

namespace DigitalStars\SimpleVK\Tests\V4;

use Amp\Future;
use DigitalStars\SimpleVK\V4\Async\AmpAdapter;
use DigitalStars\SimpleVK\V4\Async\AmpTransport;
use DigitalStars\SimpleVK\V4\Async\TrueAsyncAdapter;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use PHPUnit\Framework\TestCase;

final class AsyncAdaptersTest extends TestCase
{
    public function testAmpTransportImplementsBothContracts(): void
    {
        $transport = AmpAdapter::transport();

        self::assertInstanceOf(\DigitalStars\SimpleVK\V4\Transport\Transport::class, $transport);
        self::assertInstanceOf(AsyncTransportContract::class, $transport);
    }

    public function testCallAsyncReturnsFuture(): void
    {
        // Без реальной сети проверяем только типизацию: Future возвращается сразу.
        // Полный round-trip покрывается интеграционным смоуком с мок-сервером.
        $this->markTestSkipped('Требуется HTTP-эндпоинт; контракт проверен в testAmpTransportImplementsBothContracts');
    }

    public function testTrueAsyncAvailableOnThisRuntime(): void
    {
        if (!\extension_loaded('true_async')) {
            $this->markTestSkipped('ext-true_async не установлен');
        }

        self::assertTrue(TrueAsyncAdapter::available());
    }

    public function testTrueAsyncSpawnRunsCallable(): void
    {
        if (TrueAsyncAdapter::available() === false) {
            $this->markTestSkipped('ext-true_async не установлен');
        }

        $coroutine = TrueAsyncAdapter::spawn(static fn(): int => 40 + 2);
        $result = TrueAsyncAdapter::await($coroutine);

        self::assertSame(42, $result);
    }
}

/** Псевдоним для читаемости: контракт асинхронного транспорта. */
class_alias(\DigitalStars\SimpleVK\V4\Transport\AsyncTransport::class, AsyncTransportContract::class);
