<?php
namespace DigitalStars\SimpleVK;
use Exception;

require_once('config_simplevk.php');

class SimpleVK
{
    protected $count_send_user = 0;
    protected $version;
    protected $data = [];
    protected $data_backup = [];
    protected $api_url = 'https://api.vk.com/method/';
    protected $token;
    public static $debug_mode = 0;
    protected $auth;
    protected $request_ignore_error = REQUEST_IGNORE_ERROR;
    protected static $user_log_error = null;
    public static $confirm_str = null;
    public static $secret_str = null;

    public function __construct($token, $version, $also_version = null) {
        $this->processAuth($token, $version, $also_version);
        $this->data = json_decode(file_get_contents('php://input'), 1);
        $this->data_backup = $this->data;
        if (!empty(self::$secret_str)) {
            if (isset($this->data['secret']) && $this->data['secret'] != self::$secret_str) {
                exit('security error');
            }
        }
        if(!empty(self::$confirm_str)) {
            if (isset($this->data['type']) && $this->data['type'] == 'confirmation') {
                exit(self::$confirm_str);
            }
        }
        if (!self::$debug_mode)
            $this->sendOK();
        if(isset($this->data['object']['message']) and $this->data['type'] == 'message_new') {
            $this->data['object'] = $this->data['object']['message'];
        }
    }

    public function __call($method, $args = []) {
        $method = str_replace("_", ".", $method);
        return $this->request("$method", $args[0]);
    }

    public static function create($token, $version, $also_version = null) {
        return new self($token, $version, $also_version);
    }

    public function initVars(&$id = null, &$message = null, &$payload = null, &$user_id = null, &$type = null) {
        $data = $this->data;
        $type = isset($data['type']) ? $data['type'] : null;
        $id = isset($data['object']['peer_id']) ? $data['object']['peer_id'] : null;
        $message = isset($data['object']['text']) ? $data['object']['text'] : null;
        $payload = isset($data['object']['payload']) ? json_decode($data['object']['payload'], true) : null;
        $user_id = isset($data['object']['from_id']) ? $data['object']['from_id'] : null;
        return $this->data_backup;
    }

    public function clientSupport(&$keyboard, &$inline, &$buttons) {
        $data = $this->data_backup['object']['client_info'];
        $keyboard = $data['keyboard'];
        $inline = $data['inline_keyboard'];
        $buttons = $data['button_actions'];
        return $data;
    }

    public function reply($message, $params = []) {
        return $this->request('messages.send', ['message' => $message, 'peer_id' => $this->data['object']['peer_id']] + $params);
    }

    public function sendMessage($id, $message, $params = []) {
        return $this->request('messages.send', ['message' => $message, 'peer_id' => $id] + $params);
    }

    public function userInfo($user_url = '', $scope = []) {
        $scope = ["fields" => join(",", $scope)];
        if (isset($user_url)) {
            $user_url = preg_replace("!.*?/!", '', $user_url);
            $user_url = ($user_url == '') ? [] : ["user_ids" => $user_url];
        }
        try {
            return current($this->request('users.get', $user_url + $scope));
        } catch (Exception $e) {
            return false;
        }
    }

    public static function debug() {
        $data = json_decode(file_get_contents('php://input'), 1);
        if(isset($data['type']) && $data['type'] != 'confirmation') {
            if (empty(self::$confirm_str)) {
                ini_set('error_reporting', E_ALL);
                ini_set('display_errors', 1);
                ini_set('display_startup_errors', 1);
                echo 'ok';
                self::$debug_mode = 1;
            }
        }
    }

    public static function setUserLogError($user_id) {
        self::$user_log_error = $user_id;
    }

    public function request($method, $params = []) {
        $params['access_token'] = $this->token;
        $params['v'] = $this->version;
        $params['random_id'] = rand(-2147483648, 2147483647);
        $url = $this->api_url . $method;
        while (True) {
            try {
                return $this->request_core($url, $params);
            } catch (SimpleVkException $e) {
                if (in_array($e->getCode(), $this->request_ignore_error)) {
                    sleep(1);
                    continue;
                } else {
                    $this->sendErrorUser($e);
                    throw new Exception($e->getMessage(), $e->getCode());
                }
            }
        }
        return false;
    }

    protected function request_core($url, $params = [], $iteration = 1) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type:multipart/form-data"
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $result = json_decode(curl_exec($ch), True);
            curl_close($ch);
        } else {
            $result = json_decode(file_get_contents($url, true, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($params)
                ]
            ])), true);
        }
        if (!isset($result)) {
            if($iteration <= 5) {
                SimpleVkException::nullError('Запрос к вк вернул пустоту. Повторная отправка, попытка №' . $iteration);
                $this->request_core($url, $params, ++$iteration);
            } else {
                $error_message = "Запрос к вк вернул пустоту. Завершение 5 попыток отправки\n
                                  Метод:$url\nПараметры:\n".json_encode($params);
                SimpleVkException::nullError($error_message);
                throw new SimpleVkException(77777, $error_message);
            }
        }
        if (isset($result['error'])) {
            throw new SimpleVkException($result['error']['error_code'], json_encode($result));
        }
        if (isset($result['response']))
            return $result['response'];
        else
            return $result;
    }

    protected function sendOK() {
        set_time_limit(0);
        ini_set('display_errors', 'Off');

        // для Nginx
        if (is_callable('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
            return True;
        }
        // для Apache
        ignore_user_abort(true);

        ob_start();
        header('Content-Encoding: none');
        header('Content-Length: ' . ob_get_length());
        header('Connection: close');
        echo 'ok';
        ob_end_flush();
        flush();
        return True;
    }

    protected function sendErrorUser($e) {
        if(!is_null(self::$user_log_error)) {
            if($this->count_send_user < 1) {
                $this->count_send_user++;
                $error = SimpleVkException::userError($e);
                $this->sendMessage(self::$user_log_error, $error);
                $this->count_send_user = 0;
            }
        }
    }

    protected function processAuth($token, $version, $also_version) {
        if ($token instanceof auth) {
            $this->auth = $token;
            $this->version = $version;
            $this->token = $this->auth->getAccessToken();
        } else if (isset($also_version)) { //авторизация через аккаунт
            $this->auth = new Auth($token, $version);
            $this->token = $this->auth->getAccessToken();
            $this->version = $also_version;
        } else { //авторизация через токен
            $this->token = $token;
            $this->version = $version;
        }
    }
}