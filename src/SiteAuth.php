<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

class SiteAuth
{
    public $settings = [];

    private function __construct($settings)
    {
        if (isset($settings['client_id'], $settings['client_secret'], $settings['redirect_uri'])) {
            $this->settings = $settings;
        }
    }

    public static function create($settings = [])
    {
        return new self($settings);
    }

    public function auth($anon)
    {
        if (isset($_GET['code'])) {
            $display = $this->settings['display'] ?? 'page';
            $query = urldecode(http_build_query($this->settings + ['code' => $_GET['code'], 'display' => $display]));
            $data = json_decode(file_get_contents('https://oauth.vk.com/access_token?' . $query), true);
            foreach ($data as $key => $value) {
                if ($pos = str_contains($key, 'access_token_')) {
                    $group_id = explode('access_token_', $key)[1];
                    $data['token_groups'][$group_id] = $value;
                    unset($data[$key]);
                }
            }
            if (isset($data['access_token']) || isset($data['token_groups'])) {
                $anon($data);
            }
        } else {
            header('Location: ' . $this->get_link());
        }
    }

    public function get_link()
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

    public static function redir($url)
    {
        header('Location: ' . $url);
    }
}
