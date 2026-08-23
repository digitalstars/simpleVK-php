<?php

namespace DigitalStars\SimpleVK\V4\Config;

use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use DigitalStars\SimpleVK\V4\Transport\CurlTransport;
use DigitalStars\SimpleVK\V4\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/**
 * Иммутабельный DTO-конфигурация клиента VK.
 *
 * Создаётся через create() (именованные аргументы) или fromEnv(),
 * модифицируется with*-методами (возвращают новый объект).
 *
 * Пример:
 *   $config = ClientConfig::fromEnv()->withLogger($logger)->withRateLimit(20);
 */
final class ClientConfig
{
    public const string ENV_PREFIX_DEFAULT = 'SIMPLEVK';

    public function __construct(
        #[SensitiveParameter]
        public readonly string $token,
        public readonly int $groupId,
        public readonly string $apiVersion = '5.199',
        public readonly string $apiUrl = 'https://api.vk.com/method/',
        /** Секрет для проверки Callback API (X-Retry-Secret). */
        #[SensitiveParameter]
        public readonly ?string $confirmationSecret = null,
        /** Код подтверждения, возвращаемый в ответ на type=confirmation. */
        public readonly ?string $confirmationCode = null,
        public readonly int $retryMaxAttempts = 3,
        public readonly int $retryBackoffMs = 500,
        /** Максимум запросов к API в секунду; null — без ограничения. */
        public readonly ?float $rateLimitPerSecond = null,
        public readonly ?Transport $transport = null,
        public readonly LoggerInterface $logger = new NullLogger(),
        public readonly ?CacheInterface $cache = null,
    ) {
        if ($this->token === '') {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'Токен VK не может быть пустым');
        }
    }

    /**
     * @param non-empty-string $token
     */
    public static function create(#[SensitiveParameter] string $token, int $groupId, string $apiVersion = '5.199'): self
    {
        return new self(token: $token, groupId: $groupId, apiVersion: $apiVersion);
    }

    /**
     * Читает конфигурацию из окружения: {PREFIX}_TOKEN, {PREFIX}_GROUP_ID,
     * {PREFIX}_API_VERSION, {PREFIX}_CONFIRMATION_SECRET.
     *
     * @param non-empty-string $prefix
     */
    public static function fromEnv(string $prefix = self::ENV_PREFIX_DEFAULT): self
    {
        $token = self::envString($prefix, 'TOKEN');

        if ($token === null) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "ENV {$prefix}_TOKEN не задан. Укажите токен сообщества.",
            );
        }

        return new self(
            token: $token,
            groupId: (int) (self::envString($prefix, 'GROUP_ID') ?? '0'),
            apiVersion: self::envString($prefix, 'API_VERSION') ?? '5.199',
            confirmationSecret: self::envString($prefix, 'CONFIRMATION_SECRET'),
            confirmationCode: self::envString($prefix, 'CONFIRM_CODE'),
        );
    }

    /**
     * Достаёт строковую переменную окружения из getenv/$_ENV/$_SERVER.
     */
    private static function envString(string $prefix, string $suffix): ?string
    {
        $raw = \getenv("{$prefix}_{$suffix}");
        if ($raw === false) {
            $envValue = $_ENV["{$prefix}_{$suffix}"] ?? $_SERVER["{$prefix}_{$suffix}"] ?? null;
            $raw = \is_string($envValue) ? $envValue : null;
        }

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }

    public function withTransport(Transport $transport): self
    {
        return $this->with(transport: $transport);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return $this->with(logger: $logger);
    }

    public function withCache(CacheInterface $cache): self
    {
        return $this->with(cache: $cache);
    }

    public function withRetry(int $maxAttempts, int $backoffMs = 500): self
    {
        if ($maxAttempts < 1) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'retryMaxAttempts должен быть >= 1');
        }

        return $this->with(retryMaxAttempts: $maxAttempts, retryBackoffMs: $backoffMs);
    }

    /** Ограничение частоты запросов к API (запросов в секунду). */
    public function withRateLimit(float $requestsPerSecond): self
    {
        if ($requestsPerSecond <= 0) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'rateLimitPerSecond должен быть > 0');
        }

        return $this->with(rateLimitPerSecond: $requestsPerSecond);
    }

    public function withConfirmationSecret(#[SensitiveParameter] string $secret): self
    {
        return $this->with(confirmationSecret: $secret);
    }

    public function withConfirmationCode(string $code): self
    {
        return $this->with(confirmationCode: $code);
    }

    public function getTransport(): Transport
    {
        // Дефолт не кэшируем: DTO иммутабелен, CurlTransport дёшев в создании.
        return $this->transport ?? new CurlTransport($this->apiUrl);
    }

    private function with(
        ?Transport $transport = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        ?int $retryMaxAttempts = null,
        ?int $retryBackoffMs = null,
        ?float $rateLimitPerSecond = null,
        #[SensitiveParameter]
        ?string $confirmationSecret = null,
        ?string $confirmationCode = null,
    ): self {
        return new self(
            token: $this->token,
            groupId: $this->groupId,
            apiVersion: $this->apiVersion,
            apiUrl: $this->apiUrl,
            confirmationSecret: $confirmationSecret ?? $this->confirmationSecret,
            retryMaxAttempts: $retryMaxAttempts ?? $this->retryMaxAttempts,
            retryBackoffMs: $retryBackoffMs ?? $this->retryBackoffMs,
            rateLimitPerSecond: $rateLimitPerSecond ?? $this->rateLimitPerSecond,
            confirmationCode: $confirmationCode ?? $this->confirmationCode,
            transport: $transport ?? $this->transport,
            logger: $logger ?? $this->logger,
            cache: $cache ?? $this->cache,
        );
    }
}
