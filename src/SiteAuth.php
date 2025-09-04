<?php
/**
 * Created by PhpStorm.
 * User: runnin
 * Date: 01.08.19
 * Time: 17:56
 */

namespace DigitalStar\vk_api;


class SiteAuth {
    public $settings = [];
    public $data = [];

    public function __construct($settings) {
        if (isset($settings["client_id"], $settings["client_secret"], $settings["redirect_uri"])) {
            $this->settings = $settings;
        }
    }

    public function auth() {
        if (isset($_GET['code'])) {
            $query = urldecode(http_build_query($this->settings + ["code" => $_GET['code']]));
            $token = json_decode(file_get_contents("https://oauth.vk.ru/access_token?" . $query), true);
            if (isset($token["access_token"])) {
                $this->data = $token;
                return true;
            }
        }
        return false;
    }

    public function get_link() {
        $query = urldecode(http_build_query([
            "client_id" => $this->settings["client_id"],
            "redirect_uri" => $this->settings["redirect_uri"],
            "response_type" => "code"
        ]));
        return "https://oauth.vk.ru/authorize?" . $query;
    }
}
