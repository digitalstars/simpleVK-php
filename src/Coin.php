<?php

namespace DigitalStar\vk_api;

require_once('config_library.php');

/**
 * Class Coin
 * @package DigitalStar\vk_api
 */
class Coin {
    /**
     * @var string
     */
    protected $merchant_id = '';
    /**
     * @var object | null
     */
    private $data_request = null;
    /**
     * @var string
     */
    private $merchant_key = '';

    /**
     * vk_api constructor.
     * @param $token
     * @param $merchant_id
     */
    public function __construct($token, $merchant_id) {
        $this->merchant_key = $token;
        $this->merchant_id = $merchant_id;
    }

    /**
     * @param $token
     * @param $merchant_id
     * @return Coin
     */
    public static function create($token, $merchant_id) {
        return new self($token, $merchant_id);
    }

    /**
     * @param $user_id
     * @param int $amount
     * @return array|bool
     */
    public function sendCoins($user_id, $amount) {
        try {
            $amount = $this->request('send', ['amount' => $amount * 1000, 'toId' => $user_id]);
            if (isset($amount['amount']) && isset($amount['current'])) {
                $amount['amount'] /= 1000;
                $amount['current'] /= 1000;
            }
            return 1;
        } catch (VkApiException $e) {
            return 0;
        }
    }


    /**
     * @param array $user_ids
     * @return array|bool
     * @throws VkApiException
     */
    public function getBalance($user_ids = []) {
        if (empty($user_ids) or !is_array($user_ids))
            $user_ids = empty($user_ids) ? [$this->merchant_id] : [$user_ids];
        $results = $this->request('score', ['userIds' => $user_ids]);
        if (count($results) < count($user_ids)) {
            $nonexistent_id = join(',', (array_diff($user_ids, array_keys($results))));
            throw new VkApiException("Попытка получить баланс следущих несуществующих пользователей:\n$nonexistent_id");
        }
        $this->_toCoin($results);
        $results = array_combine($user_ids, array_values($results));
        if (is_array($user_ids) && count($user_ids) == 1)
            return $results[current($user_ids)];
        else
            return $results;
    }

    /**
     * @param string $name
     * @return array|bool
     * @throws VkApiException
     */
    public function setName($name) {
        return $this->request('set', ['name' => $name]);
    }


    /**
     * @param string $url
     * @return array|bool
     * @throws VkApiException
     */
    public function setCallBack($url = null) {
        return $this->request('set', ['callback' => $url]);
    }


    /**
     * @return array|bool
     * @throws VkApiException
     */
    public function deleteCallBack() {
        return $this->request('set', ['callback' => null]);
    }


    /**
     * @return array|bool
     * @throws VkApiException
     */
    public function getLogs() {
        return $this->request('set', ['status' => 1]);
    }

    /**
     * @param int $sum
     * @param int $payload
     * @param bool $fixed_sum
     * @param bool $to_hex
     * @return array|string
     */
    public function getLink($sum = 0, $fixed_sum = true, $payload = 0, $to_hex = false) {
        $payload = ($payload !== 0) ? $payload : rand(-2000000000, 2000000000);
        $fixed_sum = $fixed_sum ? '' : '_1';
        if ($sum === 0)
            return 'vk.ru/coin#t' . $this->merchant_id;
        $sum = (int)($sum * 1000);
        if ($to_hex) {
            $merchant_id = dechex($this->merchant_id);
            $sum = dechex($sum);
            $payload = dechex($payload);
            return ['url' => "vk.ru/coin#m{$merchant_id}_{$sum}_{$payload}{$fixed_sum}", 'payload' => $payload];
        } else {
            $merchant_id = $this->merchant_id;
            return ['url' => "vk.ru/coin#x{$merchant_id}_{$sum}_{$payload}{$fixed_sum}", 'payload' => $payload];
        }
    }

    /**
     * @param array $last_tx
     * @return bool|mixed
     * @throws VkApiException
     */
    public function getStoryShop($last_tx = []) {
        return $this->getTransaction(1, $last_tx);
    }

    /**
     * @param array $last_tx
     * @return bool|mixed
     * @throws VkApiException
     */
    public function getStoryAccount($last_tx = []) {
        return $this->getTransaction(2, $last_tx);
    }

    /**
     * @param array $transaction
     * @return bool|mixed
     * @throws VkApiException
     */
    public function getInfoTransactions($id_transactions) {
        if (is_array($id_transactions))
            return $this->getTransaction($id_transactions);
        else if (is_numeric($id_transactions))
            return $this->getTransaction([$id_transactions]);
        return 0;
    }

    /**
     * @param $from_id
     * @param $amount
     * @param $payloadа
     * @param $verify
     * @param $data
     */
    public function initVars(&$from_id, &$amount, &$payload, &$verify, &$data) {
        print 'OK';
        $data_request = json_decode(file_get_contents('php://input'));
        $data = $this->data_request = $data_request;
        if (is_object($this->data_request) &&
            isset($this->data_request->id) &&
            isset($this->data_request->from_id) &&
            isset($this->data_request->amount) &&
            isset($this->data_request->payload) &&
            isset($this->data_request->key)) {
            $from_id = $data_request->from_id;
            $payload = $data_request->payload;
            $amount = $data_request->amount;
            $verify = $this->verifyKeys();
        }
    }

    /**
     * @return bool
     */
    private function verifyKeys() {
        $parameters = [
            $this->data_request->id,
            $this->data_request->from_id,
            $this->data_request->amount,
            $this->data_request->payload,
            $this->data_request->merchant_key,
        ];
        $key = md5(implode(';', $parameters));
        return $this->data_request->key === $key;
    }
    
    /**
     * @param $tx
     * @param array $last_tx
     * @return bool|mixed
     * @throws VkApiException
     */
    private function getTransaction($tx, $last_tx = []) {
        if (!empty($last_tx))
            $last_tx = ['lastTx' => $last_tx];
        if (!is_array($tx))
            $tx = [$tx];
        $request = $this->request('tx', ['tx' => $tx] + $last_tx);
        $this->_toCoin($request);
        return $request;
    }

    /**
     * @param $results
     */
    private function _toCoin(&$results) {
        if (is_array($results))
            foreach ($results as $key => $value) {
                if (is_array($value) && isset($results[$key]['amount']))
                    @$results[$key]['amount'] = is_int($results[$key]['amount']) ?
                        (float)($value['amount'] / 1000) :
                        $results[$key]['amount'];
                else
                    $results[$key] = (float)($value / 1000);
            }
    }

    /**
     * @param $method
     * @param array $params
     * @return bool|mixed
     * @throws VkApiException
     */
    private function request($method, $params = []) {
        $params['merchantId'] = $this->merchant_id;
        $params['key'] = $this->merchant_key;

        $url = 'https://coin-without-bugs.vkforms.ru/merchant/' . $method . '/';
        try {
            return $this->request_core($url, $params);
        } catch (VkApiException $e) {
            $exception = json_decode($e->getMessage(), true);
            if (in_array($exception['error']['code'], [500, 422]))
                throw new VkApiException($exception['error']['message']);
            else
                throw new VkApiException($e->getMessage());
        }
    }

    /**
     * @param $url
     * @param array $params
     * @return mixed
     * @throws VkApiException
     */
    private function request_core($url, $params = []) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
            $result = json_decode(curl_exec($ch), True);
            curl_close($ch);
        } else {
            $result = json_decode(file_get_contents($url, true, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => http_build_query($params)
                ]
            ])), true);
        }
        if (!isset($result) or isset($result['error']))
            throw new VkApiException('Вк вернул ошибку:' . json_encode($result));
        if (isset($result['response']))
            return $result['response'];
        else
            return $result;
    }
}
