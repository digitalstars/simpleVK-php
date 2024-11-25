<?php
namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

use Exception;

trait Request {

    protected static array $proxy = [];
    protected static array $proxy_types = ['http' => CURLPROXY_HTTP, 'socks4' => CURLPROXY_SOCKS4, 'socks5' => CURLPROXY_SOCKS5];
    protected static bool $error_suppression = false;
    protected array $error_codes_for_many_try = [77777] + ERROR_CODES_FOR_MANY_TRY;
    protected function curlInit() {
        if (!function_exists('curl_init')) {
            throw new SimpleVkException(77779, 'Curl недоступен. Прекращение выполнения скрипта');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if (isset(self::$proxy['ip'])) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, self::$proxy_types[self::$proxy['type']]);
            curl_setopt($ch, CURLOPT_PROXY, self::$proxy['ip']);
            if (isset(self::$proxy['user_pwd'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, self::$proxy['user_pwd']);
            }
        }
        return $ch;
    }

    protected function runRequestWithAttempts($url, $params, $is_use_method = null) {
        for ($iteration = 1; $iteration <= 5; ++$iteration) {
            try {
//                $time_start2 = microtime(true);
                $return = $this->requestCore($url, $params, $is_use_method);
//                $this->time_checker += (microtime(true) - $time_start2);
                return $return;
            } catch (SimpleVkException $e) {
                if ($e->getCode() == 5 && isset($this->auth) && $iteration != 1) {
                    if ($iteration == 5) {
                        throw new SimpleVkException($e->getCode(), "(5/5 попыток) ".$e->getMessage());
                    }
                    $this->auth->reloadToken();
                    $this->token = $this->auth->getAccessToken();
                    continue;
                }

                if (in_array($e->getCode(), $this->error_codes_for_many_try)) {
                    if ($iteration == 5) {
                        throw new SimpleVkException($e->getCode(), "(5/5 попыток) ".$e->getMessage());
                    }
                    sleep(10);
                    continue;
                }
//                $this->time_checker += (microtime(true) - $time_start2);

                throw new SimpleVkException($e->getCode(), $e->getMessage());
            }
        }
        return null;
    }

    protected function requestCore($url, $params = [], $is_use_method = false) {
        $ch = $this->curlInit();
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:multipart/form-data'
        ]);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $json_result = curl_exec($ch);
        $result = json_decode($json_result, true);

        curl_close($ch);
        $is_json_error = (json_last_error() !== JSON_ERROR_NONE);

        if (isset($result['error']) && self::$error_suppression) {
            return $result;
        }

        if (isset($result['error']) || !isset($result) || $is_json_error || curl_errno($ch)) {
            if($is_use_method) {
                $access_token = substr($params['access_token'], 0, 10) . '****';
                $v = $params['v'];
                unset($params['access_token'], $params['v']);
                //сортировка параметров по длинне
                uasort($params, static function ($a, $b) {
                    // если массивы, то считаем их очень длинными
                    $a = (is_string($a) || is_numeric($a)) ? strlen($a) : 10000;
                    $b = (is_string($b) || is_numeric($b)) ? strlen($b) : 10000;
                    return $a - $b;
                });
                $params = [
                        'method' => $is_use_method,
                        'access_token' => $access_token,
                        'v' => $v
                    ] + $params;
            } else {
                $params['url'] = $url;
            }

            $errorCode = curl_errno($ch);

            if($errorCode == CURLE_COULDNT_CONNECT ||$errorCode == CURLE_COULDNT_RESOLVE_HOST) {
                $error_code = 77779;
                throw new SimpleVkException($error_code, 'Нет соедиения с сервером VK API. Проверьте доступность сети.');
            }

            if($errorCode == CURLE_OPERATION_TIMEOUTED) {
                $error_code = 77780;
                throw new SimpleVkException($error_code, 'Время ожидания соединения с VK API истекло.');
            }

            if (!isset($result)) {
                $result['error']['error_msg'] = 'Запрос к VK API вернул пустоту';
                $error_code = 77777;
            } else if($is_json_error) {
                $result['error']['error_msg'] = 'Запрос к VK API вернул невалидный JSON';
                $result['error']['json'] = $json_result;
                $error_code = 77778;
            } else {
                $error_code = $result['error']['error_code'] ?? 0;
            }

            if(is_string($result['error'])) {
                $result['error'] = $result;
            }

            $result['error']['request_params'] = $params;
            if(is_array($result['error'])) {
                $error_print = print_r($result['error'], true);
            } else if(is_array($result)) {
                $error_print = print_r($result, true);
            } else {
                $error_print = $result;
            }

            throw new SimpleVkException($error_code, "VK API Error!\n$error_print");
        }

        return $result['response'] ?? $result;
    }

    public static function errorSuppression($flag = true) {
        self::$error_suppression = $flag;
    }

    public static function setProxy($proxy, $pass = false) {
        self::$proxy['ip'] = $proxy;
        self::$proxy['type'] = explode(':', $proxy)[0];
        if ($pass)
            self::$proxy['user_pwd'] = $pass;
    }
}