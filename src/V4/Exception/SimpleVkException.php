<?php

namespace DigitalStars\SimpleVK\V4\Exception;

/**
 * Исключение при ошибке вызова метода VK API или сбое транспорта.
 *
 * Содержит код ошибки VK API (поле error.error_code из ответа),
 * либо внутренние отрицательные коды для транспортных сбоев.
 */
final class SimpleVkException extends \RuntimeException
{
    /** Транспортный сбой (сеть, таймаут, некорректный JSON в ответе). */
    public const TRANSPORT_ERROR = -1;

    /**
     * @param array<string, mixed> $raw Полный исходный ответ VK API (или [] для транспортных сбоев).
     */
    public function __construct(
        public readonly int $vkErrorCode,
        string $message,
        public readonly array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $vkErrorCode, $previous);
    }
}
