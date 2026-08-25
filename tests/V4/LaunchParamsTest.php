<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests\V4;

use DigitalStars\SimpleVK\V4\Auth\LaunchParams;
use PHPUnit\Framework\TestCase;

final class LaunchParamsTest extends TestCase
{
    private const string SECRET = 'wvl68m4dR1UpLrVRli';

    /** Параметры запуска из документации VK (без sign). */
    private const string DOC_PARAMS =
        'vk_user_id=494075&vk_app_id=6736218&vk_is_app_user=1'
            . '&vk_are_notifications_enabled=1&vk_language=ru&vk_access_token_settings=&vk_platform=android';

    /**
     * Канонический вектор: результат JS/Java/Python примеров той же страницы
     * документации (urlencode отсортированных vk_*-параметров).
     */
    private const string CANONIC_SIGN = 'htQFduJpLxz7ribXRZpDFUH-XEUhC9rBPTJkjUFEkRA';

    /**
     * Подпись из PHP-примера документации: противоречит JS/Java/Python примерам
     * для тех же данных. Зафиксировано тестом как НЕпроходящая подпись.
     */
    private const string PHP_DOC_SIGN = 'exTIBPYTrAKDTHLLm2AwJkmcVcvFCzQUNyoa6wAjvW6k';

    private function url(string $sign): string
    {
        return "https://example.com/?{$this->query($sign)}";
    }

    private function query(string $sign): string
    {
        return self::DOC_PARAMS . '&sign=' . $sign;
    }

    /** @return array<string, string> */
    private function parsedParams(): array
    {
        \parse_str(self::DOC_PARAMS, $params);

        return $params;
    }

    public function testVerifyCanonicalVector(): void
    {
        self::assertTrue(LaunchParams::verify($this->url(self::CANONIC_SIGN), self::SECRET));
        self::assertSame(self::CANONIC_SIGN, LaunchParams::computeSign($this->parsedParams(), self::SECRET));
    }

    public function testVerifyFromQueryStringAndParsedArray(): void
    {
        $withSign = $this->parsedParams() + ['sign' => self::CANONIC_SIGN];

        self::assertTrue(LaunchParams::verify($this->query(self::CANONIC_SIGN), self::SECRET));
        self::assertTrue(LaunchParams::verify($withSign, self::SECRET));
    }

    public function testRejectsTamperedValue(): void
    {
        $tampered = \str_replace('vk_user_id=494075', 'vk_user_id=1', $this->url(self::CANONIC_SIGN));

        self::assertFalse(LaunchParams::verify($tampered, self::SECRET));
    }

    public function testRejectsWrongSecret(): void
    {
        self::assertFalse(LaunchParams::verify($this->url(self::CANONIC_SIGN), 'other-secret'));
    }

    public function testPhpDocSampleSignIsRejectedAsStale(): void
    {
        // Документирует расхождение внутри доков VK: их PHP-пример не совпадает
        // с каноническим алгоритмом (JS/Java/Python) на тех же данных.
        self::assertFalse(LaunchParams::verify($this->url(self::PHP_DOC_SIGN), self::SECRET));
    }

    public function testParseReturnsNullWithoutVkParamsOrSign(): void
    {
        self::assertNull(LaunchParams::parse('foo=bar&baz=qux'));
        self::assertNull(LaunchParams::parse('vk_user_id=1'));
        self::assertNull(LaunchParams::parse('?sign=abc'));
    }

    public function testNonVkParamsAreIgnoredInSignature(): void
    {
        // Посторонний параметр не влияет на подпись vk_*-параметров.
        self::assertTrue(LaunchParams::verify($this->query(self::CANONIC_SIGN) . '&extra=1', self::SECRET));
    }
}
