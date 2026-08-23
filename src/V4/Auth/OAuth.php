<?php

namespace DigitalStars\SimpleVK\V4\Auth;

use SensitiveParameter;

/**
 * OAuth 2.0 VK: получение и обновление токена по коду подтверждения.
 *
 * Поток: 1) redirectUserToAuthUrl() → пользователь логинится;
 *        2) VK возвращает code на redirect_uri; 3) fetchToken(code).
 */
final class OAuth
{
    private const AUTH_URL = 'https://oauth.vk.com/authorize';
    private const TOKEN_URL = 'https://oauth.vk.com/access_token';

    public function __construct(
        private readonly int $appId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        /** @var list<string> Скоупы через пробел, например ['messages', 'groups']. */
        private readonly array $scopes = [],
    ) {}

    /**
     * URL для входа пользователя.
     *
     * @param non-empty-string $state CSRF-маркер — проверьте его в callback!
     */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL
        . '?'
        . \http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => \implode(' ', $this->scopes),
            'state' => $state,
        ]);
    }

    /**
     * Обмен кода на токен.
     *
     * @return array{access_token: string, expires_in?: int, user_id: int}
     */
    public function fetchToken(#[SensitiveParameter] string $code): array
    {
        $url =
            self::TOKEN_URL
            . '?'
            . \http_build_query([
                'client_id' => $this->appId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'code' => $code,
            ]);

        $raw = @\file_get_contents($url);

        if ($raw === false) {
            throw new \RuntimeException('OAuth: не удалось выполнить запрос к oauth.vk.com');
        }

        $data = \json_decode($raw, true);

        if (!\is_array($data) || isset($data['error'])) {
            throw new \RuntimeException('OAuth error: ' . ($data['error_description'] ?? $data['error'] ?? 'unknown'));
        }

        return [
            'access_token' => (string) $data['access_token'],
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 0,
            'user_id' => (int) $data['user_id'],
        ];
    }
}
