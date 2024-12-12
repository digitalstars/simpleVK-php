<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Internal\UniqueEventHandler;

class LongPoll extends SimpleVK {
    use ErrorHandler;

    private $key;
    private $server;
    private $ts;
    private $auth_type;
    private $is_multi_thread = false;
    private $event_flags = [];
    private static $longpoll_in_web = false;
    public static $use_user_long_poll = 0;

    public function __construct($token, $version, $also_version = null, $data = null) {
        if (php_sapi_name() !== "cli" && self::$longpoll_in_web == false)
            die("Запуск longpoll возможен только в cli. Используйте LongPoll::enableInWeb() чтобы убрать это ограничение.");
        $this->multiThread();
        $this->processAuth($token, $version, $also_version);
        $data = $this->userInfo();
        if ($data != false || self::$use_user_long_poll) {
            $this->auth_type = 'user';
        } else {
            $this->auth_type = 'group';
            $this->group_id = $this->request('groups.getById')['groups'][0]['id'];
//            $this->request('groups.setLongPollSettings', [
//                'group_id' => $this->group_id,
//                'enabled' => 1,
//                'api_version' => $this->version,
//                'message_new' => 1,
//                'message_event' => 1
//            ]);
        }
        $this->getLongPollServer();
    }

    public static function create($token, $version, $also_version = null, $data = null) {
        return new self($token, $version, $also_version, $data);
    }

    public static function enableInWeb($bool = true) {
        self::$longpoll_in_web = $bool;
    }

    /**
     * Проверить наличие модуля многопоточности и если есть включить потоки
     */
    private function multiThread() {
        $this->is_multi_thread = (extension_loaded('posix') && extension_loaded('pcntl'));
    }

    public function listen($anon) {
        while ($data = $this->processingData()) {
            foreach ($data['updates'] as $event) {

                if(isset($event['event_id'])) {
                    $is_dublicated = UniqueEventHandler::addEventToCache($event['event_id']);
                    if($is_dublicated) {
                        continue;
                    }
                }

                if ($this->is_multi_thread) {
                    while (pcntl_wait($status, WNOHANG | WUNTRACED) > 0) {
                    }
                    $pid = pcntl_fork();
                } else
                    $pid = 0;
                if ($pid == 0) {
                    unset($this->data);
                    unset($this->data_backup);
                    $this->data = $event;
                    $this->data_backup = $this->data;
                    if ($this->auth_type == 'group') {
                        if (isset($this->data['object']['message']) and $this->data['type'] == 'message_new') {
                            $this->data['object'] = $this->data['object']['message'];
                        }
                        $anon($event);
                    } else {
                        $this->userLongPoll($anon);
                    }
                    if ($this->is_multi_thread)
                        $this->__exit();
                }
            }
//            if ($this->vk instanceof Execute) {
//                $this->vk->exec();
//            }
        }
    }

    private function __exit() {
        posix_kill(posix_getpid(), SIGTERM);
    }

    private function getLongPollServer() {
        if ($this->auth_type == 'user')
            $data = $this->request('messages.getLongPollServer', ['need_pts' => 1, 'lp_version' => 10]);
        else
            $data = $this->request('groups.getLongPollServer', ['group_id' => $this->group_id]);
        unset($this->key);
        unset($this->server);
        unset($this->ts);
        list($this->key, $this->server, $this->ts) = [$data['key'], $data['server'], $data['ts']];
    }

    private function processingData() {
        while ($data = $this->getData()) {
            if (isset($data['failed'])) {
                switch ($data['failed']) {
                    case 1:
                        unset($this->ts);
                        $this->ts = $data['ts'];
                        break;
                    case 2:
                    case 3:
                        $this->getLongPollServer();
                        break;
                }
                continue;
            }

            unset($this->ts);
            $this->ts = $data['ts'];
            return $data;
        }
    }

    private function getData() {
        $default_params = ['act' => 'a_check', 'key' => $this->key, 'ts' => $this->ts, 'wait' => 25];
        try {
            if ($this->auth_type == 'user') {
                $params = ['mode' => 2 | 8 | 32 | 64 | 128, 'version' => 10];
                $data = $this->request_core_lp('https://' . $this->server . '?', $default_params + $params);
            } else {
                $data = $this->request_core_lp($this->server . '?', $default_params);
            }
            return $data;
        } catch (\Exception $e) {
            throw new SimpleVkException($e->getCode(), $e->getMessage());
        }
    }

    private function request_core_lp($url, $params = [], $iteration = 1) {
        $ch = $this->curlInit();
        curl_setopt($ch, CURLOPT_URL, $url . http_build_query($params));
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($result)) {
            if ($iteration <= 5) {
                SimpleVkException::logCustomError('Запрос к вк вернул пустоту. Повторная отправка, попытка №' . $iteration);
                $result = $this->request_core_lp($url, $params, ++$iteration); //TODO рекурсия
            } else {
                $error_message = "Запрос к вк вернул пустоту. Завершение 5 попыток отправки\n
                                  Метод:$url\nПараметры:\n" . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                SimpleVkException::logCustomError($error_message);
                throw new \Exception($error_message, 77777);
            }
        }
        return $result;
    }

//    private function parseBeginningMessageStruct($data) {
//        $this->data['object']['id'] = $data[1];
//        $this->data['object']['peer_id'] = $data[3];
//        $this->data_backup = $this->data;
//    }

    private function userLongPoll($anon) {
        $data = $this->data;
        $this->data = [];
        if (isset($data[2]))
            $this->initFlags($data[2]);
        switch ($data[0]) {
            case 2:
            { //Установка флагов сообщения
                $this->data['type'] = 'set_message_flags';
                $this->parseMessageStruct($data);
                $this->data['flags']['important'] = $this->flag(3);
                $this->data['flags']['spam'] = $this->flag(6);
                $this->data['flags']['deleted'] = $this->flag(7);
                $this->data['flags']['deleted_all'] = (int)($this->flag(7) && $this->flag(17));
                $this->data['flags']['audio_listened'] = $this->flag(12);
                break;
            }
            case 3:
            { //Сбор флагов сообщения
                $this->data['type'] = 'unset_message_flags';
                $this->parseMessageStruct($data);
                $this->data['flags']['important'] = $this->flag(3);
                $this->data['flags']['cancel_spam'] = (int)($this->flag(6) && $this->flag(15));
                $this->data['flags']['deleted'] = $this->flag(7);
                break;
            }
            case 4:
            { //входящее/исходящее сообщение
                $this->data['type'] = $this->flag(1) ? "message_reply" : "message_new";
                $this->parseMessageStruct($data);
                $this->parseMessageFlags();
                break;
            }
            case 5:
            { //редактирование сообщения
                $this->data['type'] = 'message_edit';
                $this->parseMessageStruct($data);
                $this->parseMessageFlags();
                break;
            }
            case 18:
            { //добавление сниппсета к сообщению
                $this->data['type'] = 'vk_add_snippet';
                $this->parseMessageStruct($data);
                $this->parseMessageFlags();
                break;
            }
        }
        if ($data[0] == 4) {
//            print_r($data);
//            print_r($this->data);
        }
        $this->data_backup = $this->data;
        $anon($data);
    }

    private function parseMessageFlags() {
        $this->data['flags']['unread'] = $this->flag(0);
        $this->data['flags']['chat'] = $this->flag(4);
        $this->data['flags']['friends'] = $this->flag(5);
        $this->data['flags']['chat2'] = $this->flag(13);
        $this->data['flags']['hidden'] = $this->flag(16);
        $this->data['flags']['chat_in'] = $this->flag(19);
        $this->data['flags']['silent'] = $this->flag(20);
        $this->data['flags']['reply_msg'] = $this->flag(21);
    }

    private function parseMessageStruct($data) {
        $this->data['object']['id'] = $data[1] ?? null;
        $this->data['object']['peer_id'] = $data[3] ?? null;
        if (isset($data[4])) {
            $this->data['object']['date'] = $data[4] ?? null;
            $this->data['object']['text'] = $data[5] ?? null;
            $this->data['object']['from_id'] = $data[6]['from'] ?? $this->data['object']['peer_id'];

            if (isset($data[6]['source_act'])) {
                $this->data['object']['source'] = $data[6];
            } else {
                $this->data['object']['temp']['emoji'] = $data[6]['emoji'] ?? null;
                $this->data['object']['temp']['marked_users'] = $data[6]['marked_users'][0][1] ?? null;
                $this->data['object']['temp']['keyboard'] = $data[6]['keyboard'] ?? null;
                $this->data['object']['temp']['expire_ttl'] = $data[6]['expire_ttl'] ?? null;
                $this->data['object']['temp']['ttl'] = $data[6]['ttl'] ?? null;
                $this->data['object']['temp']['is_expired'] = $data[6]['is_expired'] ?? null;
            }

            $this->data['object']['attachments'] = $data[7] ?? null;
            $this->data['object']['random_id'] = !empty($data[8]) ? $data[8] : null;
            $this->data['object']['conversation_message_id'] = $data[9] ?? null;
            $this->data['object']['edit_time'] = $data[10] ?? null; // 0 (не редактировалось) или timestamp (время редактирования)
        }
        $this->data_backup = $this->data;
    }

    private function initFlags($data) {
        $data = is_array($data) ? $data[0] : $data;
        $flags = str_split(strrev(decbin($data)));
        array_walk($flags, static function ($key, $sym) {
            return $sym * 2 ^ $key;
        });
        $this->event_flags = $flags;
    }

    private function flag($f) {
        return $this->event_flags[$f] ?? 0;
    }
}
