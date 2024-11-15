<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\simplevk\simplevk as vk;

require_once('config_simplevk.php');

class Auth {
    use Request;
    private $login = null;
    private $pass = null;
    private $cookie = null;
    private $useragent = DEFAULT_USERAGENT;
    private $access_token = '';
    private $scope = DEFAULT_SCOPE;
    private $method = 1; // 1 - official_app, 2 - app
    private $app = DEFAULT_APP['android'];
    private $id_app = null;
    private $is_save = AUTO_SAVE_AUTH;
    private $default_app = DEFAULT_APP;
    private $is_update = false;
    private $cashed_salt = "<?php http_response_code(404);exit('404');?>";
    private $captcha_handler_func = null;

    public static function create($login = null, $pass = null) {
        return new self($login, $pass);
    }

    public function __construct($login = null, $pass = null) {
        if (isset($login) and isset($pass)) {
            $this->login = urlencode($login);
            $this->pass = urlencode($pass);
            $this->loadCashed();
        }
    }

    public function login($login) {
        $this->login = $login;
        if ($login != $this->login)
            $this->access_token = '';
        if (isset($this->pass))
            $this->loadCashed();
        return $this;
    }

    public function pass($pass) {
        $this->pass = $pass;
        if ($pass != $this->pass)
            $this->access_token = '';
        if (isset($this->login))
            $this->loadCashed();
        return $this;
    }

    public function cookie($cookie) {
        if (is_string($cookie))
            $cookie = json_decode($cookie, true);
        $this->cookie = $cookie;
        return $this;
    }

    public function useragent($useragent) {
        $this->useragent = $useragent;
        return $this;
    }

    public function app($app) {
        if (is_numeric($app)) {
            if ($this->id_app != $app) {
                $this->access_token = '';
                $this->id_app = $app;
                $this->method = 2;
            }
        } else if (isset($this->default_app[strtolower($app)])) {
            if ($this->default_app[strtolower($app)] != $this->app) {
                $this->access_token = '';
                $this->app = $this->default_app[strtolower($app)];
                $this->method = 1;
            }
        } else {
            throw new SimpleVkException(0, "Недопустимое значение для идентификатора приложения");
        }
        return $this;
    }

    public function scope($scope) {
        if ($scope != $this->scope) {
            $this->scope = $scope;
            $this->access_token = '';
        }
        return $this;
    }

    public function save($is) {
        $this->is_save = $is;
        if (!$this->is_update) {
            $this->access_token = '';
            $this->cookie = [];
        }
        return $this;
    }

    public function captchaHandler($func) {
        $this->captcha_handler_func = $func;
        return $this;
    }

    public function auth() {
        if ($this->method != 2)
            throw new SimpleVkException(0, "Только для авторизации через приложение");
        if ($this->isAuth() == 0)
            $this->loginInVK();
        return $this->isAuth();
    }

    public function dumpCookie() {
        if ($this->cookie == null or $this->method == 1)
            return false;
        return json_encode($this->cookie, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function isAuth() {
        if ($this->method == 0 or ($this->access_token == '' and $this->method == 1))
            return 0;
        if ($this->access_token != '') {
            $check_valid_token = json_decode($this->getCURL("https://api.vk.com/method/users.get?v=5.103&access_token=" . $this->access_token)['body'], true);
            if (isset($check_valid_token['response'])) {
                return 1;
            }
            if ($this->method = 1)
                return 0;
        }
        $header = $this->getCURL("https://vk.com/feed")['header'];
        if (isset($header['location'][0]) and strpos($header['location'][0], 'login.vk.com')) {
            $this->cookie = null;
            return 0;
        }
        return 2;
    }

    public function getAccessToken() {
        if ($this->access_token != '')
            return $this->access_token;
        if ($this->method == 1) {
            $this->access_token = $this->generateAccessTokenOfficialApp();
        } else if ($this->method == 2) {
            if ($this->isAuth() == 0)
                $this->loginInVK();
            try {
                $this->access_token = $this->generateAccessTokenApp();
            } catch (\Exception $e) {
                if (isset($this->login) and isset($this->pass)) {
                    $this->cookie = null;
                    $this->loginInVK();
                    $this->access_token = $this->generateAccessTokenApp();
                } else {
                    throw new SimpleVkException(0, "Через куки не выходит получить токен, а логин и пароль не заданы");
                }
            }
        }
        $this->is_update = true;
        $this->saveCashed();
        return $this->access_token;
    }

    public function reloadToken() {
        $this->access_token = '';
        return $this->getAccessToken();
    }

    private function generateAccessTokenApp($resend = false) {
        $scope = "&scope=" . $this->scope;

        if ($resend)
            $scope .= "&revoke=1";

        $token_url = 'https://oauth.vk.com/authorize?client_id=' . $this->id_app . $scope . '&response_type=token';

        $get_url_token = $this->getCURL($token_url);

        if (isset($get_url_token['header']['location'][0]))
            $url_token = $get_url_token['header']['location'][0];
        else {
            preg_match('!location.href = "(.*)"\+addr!s', $get_url_token['body'], $url_token);

            if (!isset($url_token[1])) {
                throw new SimpleVkException(0, "Не получилось получить токен на этапе получения ссылки подтверждения");
            }
            $url_token = $url_token[1];
        }

        $access_token_location = $this->getCURL($url_token)['header']['location'][0];

        if (preg_match("!access_token=(.*?)&!s", $access_token_location, $access_token) != 1)
            throw new SimpleVkException(0, "Не удалось найти access_token в строке ридеректа, ошибка:" . $this->getCURL($access_token_location, null, false)['body']);
        return $access_token[1];
    }

    private function generateAccessTokenOfficialApp($captcha_key = false, $captcha_sid = false) {
        if (!isset($this->login) or !isset($this->pass))
            throw new SimpleVkException(0, "Для авторизации через оффициальное приложение необходимо задать логин и пароль");

        $captcha = '';
        $scope = "&scope=" . $this->scope;

        if ($captcha_key and $captcha_sid)
            $captcha = "&captcha_sid=$captcha_sid&captcha_key=$captcha_key";

        $token_url = 'https://oauth.vk.com/token?grant_type=password' .
            '&client_id=' . $this->app['id'] .
            '&client_secret=' . $this->app['secret'] .
            '&username=' . $this->login .
            '&password=' . $this->pass .
            $scope .
            $captcha;
        $response_auth = $this->getCURL($token_url, null, false)['body'];
        $response_auth = json_decode($response_auth, true);

        if (isset($response_auth['access_token']))
            return $response_auth['access_token'];
        else if (isset($response_auth['error']) and $response_auth['error'] == 'need_captcha') {
            if (is_callable($this->captcha_handler_func))
                return $this->generateAccessTokenOfficialApp(call_user_func_array($this->captcha_handler_func,
                    [$response_auth['captcha_sid'], $response_auth['captcha_img']]), $response_auth['captcha_sid']);
        }
        if (isset($response_auth['error']))
            throw new SimpleVkException(0, json_encode($response_auth, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function saveCashed() {
        if ($this->is_save) {
            if (!is_dir(__DIR__ . "/cache") && !mkdir($concurrentDirectory = __DIR__ . "/cache") && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
            $path = __DIR__ . "/cache/" . hash('sha256', $this->login . $this->pass) . ".php";
            file_put_contents($path, $this->cashed_salt .
                base64_encode(
                    json_encode(
                        [$this->cookie,
                            $this->access_token,
                            $this->method,
                            $this->scope,
                            $this->id_app,
                            $this->app]
                    )
                )
            );
        }
    }

    private function loadCashed() {
        if ($this->is_save) {
            $path = __DIR__ . "/cache/" . hash('sha256', $this->login . $this->pass) . ".php";
            if (file_exists($path)) {
                $cashed_data = json_decode(
                    base64_decode(
                        str_replace($this->cashed_salt, '', @file_get_contents($path))
                    )
                    , true);
                if ($cashed_data != '') {
                    list($this->cookie,
                        $this->access_token,
                        $this->method,
                        $this->scope,
                        $this->id_app,
                        $this->app) = $cashed_data;
                }
            }
        }
    }

    private function loginInVK() {
        if (!isset($this->login) or !isset($this->pass))
            throw new SimpleVkException(0, "Для авторизации через приложение необходимо задать логин и пароль, либо куки");

        $query_main_page = $this->getCURL('https://vk.com/');
        preg_match('/name=\"ip_h\" value=\"(.*?)\"/s', $query_main_page['body'], $ip_h);
        preg_match('/name=\"lg_h\" value=\"(.*?)\"/s', $query_main_page['body'], $lg_h);

        $values_auth = [
            'act' => 'login',
            'role' => 'al_frame',
            '_origin' => 'https://vk.com',
            'utf8' => '1',
            'email' => $this->login,
            'pass' => $this->pass,
            'lg_h' => $lg_h[1],
            'ig_h' => $ip_h[1]
        ];
        $get_url_redirect_auch = $this->getCURL('https://login.vk.com/?act=login', $values_auth);

        if (!isset($get_url_redirect_auch['header']['location']))
            throw new SimpleVkException(0, "Ошибка, ссылка редиректа не получена");

        $auth_page = $this->getCURL($get_url_redirect_auch['header']['location'][0]);

        if (!isset($auth_page['header']['set-cookie']))
            throw new SimpleVkException(0, "Ошибка, куки пользователя не получены");
    }

    private function getCURL($url, $post_values = null, $cookie = true) {
        $curl = $this->curlInit();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->useragent);

        if (isset($post_values)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post_values);
        }

        if ($cookie && isset($this->cookie)) {
            $send_cookie = [];
            foreach ($this->cookie as $cookie_name => $cookie_val) {
                $send_cookie[] = "$cookie_name=$cookie_val";
            }
            curl_setopt($curl, CURLOPT_COOKIE, implode('; ', $send_cookie));
        }

        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
            static function ($curl, $header) use (&$headers) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $name = strtolower(trim($header[0]));
                if (isset($headers) && !array_key_exists($name, $headers)) {
                    $headers[$name] = [trim($header[1])];
                }
                else {
                    $headers[$name][] = trim($header[1]);
                }

                return $len;
            }
        );

        $out = curl_exec($curl);
        curl_close($curl);
        if (isset($headers['set-cookie'])) {
            $this->parseCookie($headers['set-cookie']);
        }
        return ['header' => $headers, 'body' => $out];
    }

    private function parseCookie($new_cookie) {
        foreach ($new_cookie as $cookie) {
            preg_match("!(.*?)=(.*?);(.*)!s", $cookie, $preger);
            if ($preger[2] == 'DELETED')
                unset($this->cookie[$preger[1]]);
            else
                $this->cookie[$preger[1]] = $preger[2] . ';' . $preger[3];
        }
    }
}
