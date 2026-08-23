<?php

namespace DigitalStars\SimpleVK;

/**
 * OAuth-авторизация сайтовых приложений VK (получение code и access_token).
 */
class SiteAuth
{
    /** @var array<string, mixed> Настройки: client_id, client_secret, redirect_uri, scope, group_ids, display. */
    public array $settings = [];

    private function __construct(array $settings)
    {
        if (isset($settings['client_id'], $settings['client_secret'], $settings['redirect_uri'])) {
            $this->settings = $settings;
        }
    }

    public static function create(array $settings = []): static
    {
        return new self($settings);
    }

    /**
     * Обрабатывает OAuth-колбэк: при наличии ?code обменивает его на токен
     * и вызывает обработчик, иначе редиректит на страницу авторизации.
     *
     * @param callable $anon fn(array $tokenData): void — access_token / token_groups.
     */
    public function auth(callable $anon): void
    {
        if (isset($_GET['code'])) {
            $display = $this->settings['display'] ?? 'page';
            $query = urldecode(http_build_query($this->settings + ['code' => $_GET['code'], 'display' => $display]));
            $raw = @file_get_contents('https://oauth.vk.com/access_token?' . $query);
            $data = json_decode((string) $raw, true);
            // ФИКС: раньше json_decode получал false при ошибке запроса и падал на foreach
            if (!is_array($data)) {
                return;
            }
            foreach ($data as $key => $value) {
                if (str_contains((string) $key, 'access_token_')) {
                    $group_id = explode('access_token_', (string) $key)[1];
                    $data['token_groups'][$group_id] = $value;
                    unset($data[$key]);
                }
            }
            if (isset($data['access_token']) || isset($data['token_groups'])) {
                $anon($data);
            }
        } else {
            self::redir($this->get_link());
        }
    }

    /** Строит ссылку на страницу авторизации VK. */
    public function get_link(): string
    {
        $scope = isset($this->settings['scope']) ? ['scope' => $this->settings['scope']] : [];
        $group_ids = isset($this->settings['group_ids'])
            ? ['group_ids' => implode(',', $this->settings['group_ids'])]
            : [];
        $params =
            [
                'client_id' => $this->settings['client_id'],
                'redirect_uri' => $this->settings['redirect_uri'],
                'response_type' => 'code',
            ]
            + $scope
            + $group_ids;
        $query = urldecode(http_build_query($params));
        return 'https://oauth.vk.com/authorize?' . $query;
    }

    /** Отправляет Location-заголовок. */
    public static function redir(string $url): void
    {
        header('Location: ' . $url);
    }
}
