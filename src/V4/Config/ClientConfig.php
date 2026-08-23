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
    public const ENV_PREFIX_DEFAULT = 'SIMPLEVK';

    public function __construct(
        #[SensitiveParameter]
        public readonly string $token,
        public readonly int $groupId,
        public readonly string $apiVersion = '5.199',
        public readonly string $apiUrl = 'https://api.vk.com/method/',
        /** Секрет для подтверждения серверов Callback API. */
        #[SensitiveParameter]
        public readonly ?string $confirmationSecret = null,
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
        $token = \getenv("{$prefix}_TOKEN") ?: $_ENV["{$prefix}_TOKEN"] ?? $_SERVER["{$prefix}_TOKEN"] ?? '';
        if ($token === '') {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "ENV {$prefix}_TOKEN не задан. Укажите токен сообщества.",
            );
        }

        $groupIdRaw = \getenv("{$prefix}_GROUP_ID")
        ?: $_ENV["{$prefix}_GROUP_ID"] ?? $_SERVER["{$prefix}_GROUP_ID"] ?? '0';
        $versionRaw = \getenv("{$prefix}_API_VERSION") ?: $_ENV["{$prefix}_API_VERSION"] ?? '5.199';
        $secretRaw = \getenv("{$prefix}_CONFIRMATION_SECRET") ?: $_ENV["{$prefix}_CONFIRMATION_SECRET"] ?? '';

        return new self(
            token: (string) $token,
            groupId: (int) $groupIdRaw,
            apiVersion: (string) $versionRaw,
            confirmationSecret: $secretRaw !== '' ? (string) $secretRaw : null,
        );
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
            transport: $transport ?? $this->transport,
            logger: $logger ?? $this->logger,
            cache: $cache ?? $this->cache,
        );
    }
}
