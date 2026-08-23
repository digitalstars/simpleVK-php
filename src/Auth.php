<?php

namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

/**
 * Авторизация по логину/паролю (официальное приложение или стороннее приложение/куки)
 * с кэшированием токена и куки.
 */
class Auth {
    use Request;

    private ?string $login = null;
    private ?string $pass = null;
    /** @var array<string, string>|null */
    private ?array $cookie = null;
    private string $useragent = DEFAULT_USERAGENT;
    private string $access_token = '';
    private string $scope = DEFAULT_SCOPE;
    private int $method = 1; // 1 - official_app, 2 - app
    /** @var array{id: int|string, secret: string} */
    private array $app = DEFAULT_APP['android'];
    private ?int $id_app = null;
    private bool $is_save = AUTO_SAVE_AUTH;
    /** @var array<string, array> */
    private array $default_app = DEFAULT_APP;
    private bool $is_update = false;
    private string $cashed_salt = "<?php http_response_code(404);exit('404');?>";
    /** @var callable|null fn(string $captcha_sid, string $captcha_img): string */
    private $captcha_handler_func = null;

    public static function create(?string $login = null, ?string $pass = null): static {
        return new self($login, $pass);
    }

    public function __construct(?string $login = null, ?string $pass = null) {
        if (isset($login) and isset($pass)) {
            $this->setLogin($login);
            $this->setPass($pass);
            $this->loadCashed();
        }
    }

    /**
     * Задаёт логин. Сбрасывает токен, если логин изменился.
     */
    public function login(string $login): static {
        if ($login !== $this->login) {
            $this->access_token = '';
        }
        $this->setLogin($login);
        if (isset($this->pass))
            $this->loadCashed();
        return $this;
    }

    /**
     * Задаёт пароль. Сбрасывает токен, если пароль изменился.
     */
    public function pass(string $pass): static {
        // ФИКС: раньше сравнение шло после присваивания и токен никогда не сбрасывался
        if ($pass !== $this->pass) {
            $this->access_token = '';
        }
        $this->setPass($pass);
        if (isset($this->login))
            $this->loadCashed();
        return $this;
    }

    private function setLogin(string $login): void {
        $this->login = urlencode($login);
    }

    private function setPass(string $pass): void {
        $this->pass = urlencode($pass);
    }

    /**
     * Задаёт куки (JSON-строка или массив).
     *
     * @param string|array $cookie
     */
    public function cookie(string|array|null $cookie): static {
        if (is_string($cookie))
            $cookie = json_decode($cookie, true);
        $this->cookie = $cookie;
        return $this;
    }

    public function useragent(string $useragent): static {
        $this->useragent = $useragent;
        return $this;
    }

    /**
     * Выбирает приложение авторизации: числовой app_id (стороннее приложение)
     * или имя официального ('android', 'iphone', 'ipad', 'windows_desktop', 'vk_messenger').
     */
    public function app(int|string $app): static {
        if (is_numeric($app)) {
            if ($this->id_app != $app) {
                $this->access_token = '';
                $this->id_app = (int)$app;
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

    /** Права доступа (scope). Смена сбрасывает токен. */
    public function scope(string $scope): static {
        if ($scope != $this->scope) {
            $this->scope = $scope;
            $this->access_token = '';
        }
        return $this;
    }

    /**
     * Включает/выключает автосохранение токена и куки в файловый кэш.
     */
    public function save(bool $is): static {
        $this->is_save = $is;
        if (!$this->is_update) {
            $this->access_token = '';
            $this->cookie = [];
        }
        return $this;
    }

    /**
     * Колбэк для ввода капчи: fn(string $captcha_sid, string $captcha_img): string.
     */
    public function captchaHandler(callable $func): static {
        $this->captcha_handler_func = $func;
        return $this;
    }

    /**
     * Проходит авторизацию через стороннее приложение при необходимости.
     *
     * @return int 0 — не авторизован, 1 — токен валиден, 2 — есть живые куки.
     */
    public function auth(): int {
        if ($this->method != 2)
            throw new SimpleVkException(0, "Только для авторизации через приложение");
        if ($this->isAuth() == 0)
            $this->loginInVK();
        return $this->isAuth();
    }

    /** @return string|false JSON кук или false. */
    public function dumpCookie(): string|false {
        if ($this->cookie == null or $this->method == 1)
            return false;
        return json_encode($this->cookie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Проверяет состояние авторизации.
     *
     * @return int 0 — нужна авторизация, 1 — токен валиден, 2 — авторизация по кукам жива.
     */
    public function isAuth(): int {
        if ($this->method == 0 or ($this->access_token == '' and $this->method == 1))
            return 0;
        if ($this->access_token != '') {
            $check_valid_token = json_decode((string)$this->getCURL("https://api.vk.ru/method/users.get?v=5.103&access_token=" . $this->access_token)['body'], true);
            if (isset($check_valid_token['response'])) {
                return 1;
            }
            // ФИКС: раньше здесь было присваивание `$this->method = 1` вместо сравнения,
            // что всегда давало true и затирало method=2
            if ($this->method == 1)
                return 0;
        }
        $header = $this->getCURL("https://vk.ru/feed")['header'];
        if (isset($header['location'][0]) && str_contains((string)$header['location'][0], 'login.vk.ru')) {
            $this->cookie = null;
            return 0;
        }
        return 2;
    }

    /**
     * Возвращает access_token, выполняя авторизацию при необходимости.
     *
     * @throws SimpleVkException
     */
    public function getAccessToken(): string {
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

    /** Сбрасывает токен и получает новый. */
    public function reloadToken(): string {
        $this->access_token = '';
        return $this->getAccessToken();
    }

    /**
     * Получает токен через oauth-авторизацию стороннего приложения по кукам.
     */
    private function generateAccessTokenApp(bool $resend = false): string {
        $scope = "&scope=" . $this->scope;

        if ($resend)
            $scope .= "&revoke=1";

        $token_url = 'https://oauth.vk.ru/authorize?client_id=' . $this->id_app . $scope . '&response_type=token';

        $get_url_token = $this->getCURL($token_url);

        if (isset($get_url_token['header']['location'][0]))
            $url_token = $get_url_token['header']['location'][0];
        else {
            preg_match('!location.href = "(.*)"\+addr!s', (string)$get_url_token['body'], $url_token);

            if (!isset($url_token[1])) {
                throw new SimpleVkException(0, "Не получилось получить токен на этапе получения ссылки подтверждения");
            }
            $url_token = $url_token[1];
        }

        $access_token_location = $this->getCURL($url_token)['header']['location'][0];

        if (preg_match("!access_token=(.*?)&!s", (string)$access_token_location, $access_token) != 1)
            throw new SimpleVkException(0, "Не удалось найти access_token в строке ридеректа, ошибка:" . $this->getCURL($access_token_location, null, false)['body']);
        return $access_token[1];
    }

    /**
     * Получает токен по протоколу официального приложения (логин+пароль), с поддержкой капчи.
     */
    private function generateAccessTokenOfficialApp(string|false $captcha_key = false, string|false $captcha_sid = false): string {
        if (!isset($this->login) or !isset($this->pass))
            throw new SimpleVkException(0, "Для авторизации через оффициальное приложение необходимо задать логин и пароль");

        $captcha = '';
        $scope = "&scope=" . $this->scope;

        if ($captcha_key and $captcha_sid)
            $captcha = "&captcha_sid=$captcha_sid&captcha_key=$captcha_key";

        $token_url = 'https://oauth.vk.ru/token?grant_type=password' .
            '&client_id=' . $this->app['id'] .
            '&client_secret=' . $this->app['secret'] .
            '&username=' . $this->login .
            '&password=' . $this->pass .
            $scope .
            $captcha;
        $response_auth = json_decode((string)$this->getCURL($token_url, null, false)['body'], true);

        if (isset($response_auth['access_token']))
            return (string)$response_auth['access_token'];

        if (isset($response_auth['error']) and $response_auth['error'] == 'need_captcha') {
            if (is_callable($this->captcha_handler_func)) {
                return $this->generateAccessTokenOfficialApp(
                    call_user_func_array($this->captcha_handler_func,
                        [$response_auth['captcha_sid'], $response_auth['captcha_img']]),
                    $response_auth['captcha_sid']
                );
            }
        }
        if (isset($response_auth['error']))
            throw new SimpleVkException(0, json_encode($response_auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        throw new SimpleVkException(0, "Неизвестный ответ при получении токена");
    }

    private function saveCashed(): void {
        if ($this->is_save) {
            if (!is_dir(__DIR__ . "/cache") && !mkdir($concurrentDirectory = __DIR__ . "/cache") && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
            $path = __DIR__ . "/cache/" . hash('sha256', ($this->login ?? '') . ($this->pass ?? '')) . ".php";
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

    private function loadCashed(): void {
        if ($this->is_save) {
            $path = __DIR__ . "/cache/" . hash('sha256', ($this->login ?? '') . ($this->pass ?? '')) . ".php";
            if (file_exists($path)) {
                $cashed_data = json_decode(
                    base64_decode(
                        str_replace($this->cashed_salt, '', (string)@file_get_contents($path))
                    )
                    , true);
                if (is_array($cashed_data)) {
                    list($this->cookie,
                        $this->access_token,
                        $this->method,
                        $this->scope,
                        $this->id_app,
                        $this->app) = $cashed_data + [null, '', 1, DEFAULT_SCOPE, null, DEFAULT_APP['android']];
                }
            }
        }
    }

    private function loginInVK(): void {
        if (!isset($this->login) or !isset($this->pass))
            throw new SimpleVkException(0, "Для авторизации через приложение необходимо задать логин и пароль, либо куки");

        $query_main_page = $this->getCURL('https://vk.ru/');
        preg_match('/name="ip_h" value="(.*?)"/s', (string)$query_main_page['body'], $ip_h);
        preg_match('/name="lg_h" value="(.*?)"/s', (string)$query_main_page['body'], $lg_h);

        $values_auth = [
            'act' => 'login',
            'role' => 'al_frame',
            '_origin' => 'https://vk.ru',
            'utf8' => '1',
            'email' => $this->login,
            'pass' => $this->pass,
            'lg_h' => $lg_h[1] ?? '',
            // ФИКС: поле формы называется ip_h (раньше отправлялось как ig_h)
            'ip_h' => $ip_h[1] ?? ''
        ];
        $get_url_redirect_auch = $this->getCURL('https://login.vk.ru/?act=login', $values_auth);

        if (!isset($get_url_redirect_auch['header']['location']))
            throw new SimpleVkException(0, "Ошибка, ссылка редиректа не получена");

        $auth_page = $this->getCURL($get_url_redirect_auch['header']['location'][0]);

        if (!isset($auth_page['header']['set-cookie']))
            throw new SimpleVkException(0, "Ошибка, куки пользователя не получены");
    }

    /**
     * curl-запрос с сохранением заголовков и автоматическим обновлением кук.
     *
     * @return array{header: array<string, array<string>>, body: string|false}
     */
    private function getCURL(string $url, ?array $post_values = null, bool $cookie = true): array {
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

        $headers = [];
        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
            static function ($curl, $header) use (&$headers) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) < 2) { // ignore invalid headers
                    return $len;
                }

                $name = strtolower(trim($parts[0]));
                if (!array_key_exists($name, $headers)) {
                    $headers[$name] = [trim($parts[1])];
                } else {
                    $headers[$name][] = trim($parts[1]);
                }

                return $len;
            }
        );

        $out = curl_exec($curl);
        unset($curl);
        if (isset($headers['set-cookie'])) {
            $this->parseCookie($headers['set-cookie']);
        }
        return ['header' => $headers, 'body' => $out];
    }

    /**
     * @param array<string> $new_cookie Значения set-cookie.
     */
    private function parseCookie(array $new_cookie): void {
        foreach ($new_cookie as $cookie) {
            preg_match("!(.*?)=(.*?);(.*)!s", $cookie, $preger);
            if (($preger[2] ?? '') === 'DELETED') {
                unset($this->cookie[$preger[1]]);
            } elseif (isset($preger[1])) {
                $this->cookie[$preger[1]] = ($preger[2] ?? '') . ';' . ($preger[3] ?? '');
            }
        }
    }
}
