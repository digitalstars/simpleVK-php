<?php

namespace DigitalStar\vk_api;

use CURLFile;
use Exception;

require_once('config_library.php');

/**
 * Class vk_api
 * @package DigitalStar\vk_api
 */
class vk_api {

    /**
     * @var string
     */
    protected $version = '';
    /**
     * @var array|mixed
     */
    protected $data = [];
    /**
     * @var string
     */
    protected $auth_type = '';
    /**
     * @var string
     */
    private $token = '';
    /**
     * @var int
     */
    protected $debug_mode = 0;
    /**
     * @var Auth|null
     */
    private $auth = null;
    /**
     * @var array
     */
    private $request_ignore_error = REQUEST_IGNORE_ERROR;
    /**
     * @var int
     */
    protected $try_count_resend_file = COUNT_TRY_SEND_FILE;

    /**
     * vk_api constructor.
     * @param $token
     * @param $version
     * @param null $also_version
     * @throws VkApiException
     */
    public function __construct($token, $version, $also_version = null) {
        if ($token instanceof auth) {
            $this->auth = $token;
            $this->version = $version;
            $this->token = $this->auth->getAccessToken();
        } else if (isset($also_version)) {
            $this->auth = new Auth($token, $version);
            $this->token = $this->auth->getAccessToken();
            $this->version = $also_version;
        } else {
            $this->token = $token;
            $this->version = $version;
        }
        $this->data = json_decode(file_get_contents('php://input'));
    }

    /**
     * @param $token
     * @param $version
     * @param null $also_version
     * @return vk_api
     *
     * @throws VkApiException
     */
    public static function create($token, $version, $also_version = null) {
        return new self($token, $version, $also_version);
    }

    /**
     * @param $str
     * @return vk_api
     */
    public function setConfirm($str) {
        if (isset($this->data->type) && $this->data->type == 'confirmation') { //Если vk запрашивает ключ
            exit($str); //Завершаем скрипт отправкой ключа
        }
        return $this;
    }

    /**
     * @param null $id
     * @param null $message
     * @param null $payload
     * @param null $user_id
     * @param null $type
     * @param null $data
     * @return array|mixed|null
     */
    public function initVars(&$id = null, &$message = null, &$payload = null, &$user_id = null, &$type = null, &$data = null) {
        if (!$this->debug_mode)
            $this->sendOK();
        $data = $this->data;
        $data_backup = $this->data;
        $type = isset($data->type) ? $data->type : null;
        if(isset($data->object->message) and $type == 'message_new') {
            $data->object = $data->object->message; //какая-то дичь с ссылками, но $this->data теперь тоже переопределился
        }
        $id = isset($data->object->peer_id) ? $data->object->peer_id : null;
        $message = isset($data->object->text) ? $data->object->text : null;
        $payload = isset($data->object->payload) ? json_decode($data->object->payload, true) : null;
        $user_id = isset($data->object->from_id) ? $data->object->from_id : null;
        $data = $data_backup;
        return $data_backup;
    }

    /**
     * @return bool
     */
    protected function sendOK() {
        set_time_limit(0);
        ini_set('display_errors', 'Off');
        ob_end_clean();

        // для Nginx
        if (is_callable('fastcgi_finish_request')) {
            echo 'ok';
            session_write_close();
            fastcgi_finish_request();
            return True;
        }
        // для Apache
        ignore_user_abort(true);

        ob_start();
        header('Content-Encoding: none');
        header('Content-Length: 2');
        header('Connection: close');
        echo 'ok';
        ob_end_flush();
        flush();
        return True;
    }

    /**
     * @param $message
     * @param array $params
     * @return bool|mixed
     * @throws VkApiException
     */
    public function reply($message, $params = []) {
        if ($this->data != []) {
            $message = $this->placeholders($this->data->object->peer_id, $message);
            return $this->request('messages.send', ['message' => $message, 'peer_id' => $this->data->object->peer_id] + $params);
        } else {
            throw new VkApiException('Вк не прислал callback, возможно вы пытаетесь запустить скрипт с локалки');
        }
    }

    public function forward($id, $id_messages, $params = []) {
        $forward_messages = (is_array($id_messages)) ? join(',', $id_messages) : $id_messages;
        return $this->request('messages.send', ['peer_id' => $id, 'forward_messages' => $forward_messages] + $params);
    }

    public function sendAllChats($message, $params = []) {
        unset($this->request_ignore_error[array_search(10, $this->request_ignore_error)]); //убираем код 10 из исключений
        $i = 0;
        $count = 0;
        print "Начинаю перебор всех бесед...\n";
        while (true) {
            print(++$i . " ");
            try {
                $this->sendMessage(2000000000 + $i, $message, $params);
                $count++;
            } catch (VkApiException $e) {
                if ($e->getCode() == 10) {
                    print "\nВсего было разослано в $count бесед";
                    break;
                }
            }
        }
    }

    protected function placeholders($id, $message) {
        if($id >= 2000000000) {
            $id = isset($this->data->object->from_id) ? $this->data->object->from_id : null;
        }
        if($id == null) {
            print "Попытка использовать заполнители при передаче id беседы";
            return $message;
        } else {
            if (strpos($message, '%') !== false) {
                $data = $this->userInfo($id);
                $f = $data['first_name'];
                $l = $data['last_name'];
                $tag = ['%fn%', '%ln%', '%full%', '%a_fn%', '%a_ln%', '%a_full%'];
                $replace = [$f, $l, "$f $l", "@id{$id}($f)", "@id{$id}($l)", "@id{$id}($f $l)"];
                return str_replace($tag, $replace, $message);
            } else
                return $message;
        }
    }

    /**
     * @param $method
     * @param array $params
     * @return bool|mixed
     * @throws VkApiException
     */
    public function request($method, $params = []) {
        list($method, $params) = $this->editRequestParams($method, $params);
        $url = 'https://api.vk.ru/method/' . $method;
        $params['access_token'] = $this->token;
        $params['v'] = $this->version;
        $params['random_id'] = rand(-2147483648, 2147483647);

        while (True) {
            try {
                return $this->request_core($url, $params);
            } catch (VkApiException $e) {
                if (in_array($e->getCode(), $this->request_ignore_error)) {
                    sleep(1);
                    continue;
                }
                else
                    throw new VkApiException($e->getMessage(), $e->getCode());
            }
        }
        return false;
    }

    /**
     * @param $method
     * @param $params
     * @return array
     */
    protected function editRequestParams($method, $params) {
        return [$method, $params];
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
                "Content-Type:multipart/form-data"
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
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
        if (!isset($result))
            $this->request_core($url, $params);
        if (isset($result['error'])) {
            throw new VkApiException(json_encode($result), $result['error']['error_code']);
        }
        if (isset($result['response']))
            return $result['response'];
        else
            return $result;
    }

    /**
     * @param $message
     * @param null $keyboard
     * @param string $filter
     * @param array $params
     * @throws VkApiException
     */
    public function sendAllDialogs($message, $keyboard = null, $filter = 'all', $params = []) {
        $ids = [];
        for ($count_all = 1, $offset = 0; $offset <= $count_all; $offset += 200) {
            $members = $this->request('messages.getConversations', ['count' => 200, 'offset' => $offset, 'filter' => $filter]);//'filter' => 'unread'
            if ($count_all != 1)
                $offset += $members['count'] - $count_all;
            $count_all = $members['count'];

            foreach ($members["items"] as $user)
                if ($user['conversation']["can_write"]["allowed"] == true)
                    $ids [] = $user['conversation']['peer']['id'];
        }
        $ids = array_chunk($ids, 100);
        foreach ($ids as $ids_chunk) {
            try {
                $this->request('messages.send', ['user_ids' => join(',', $ids_chunk), 'message' => $message, 'keyboard' => $keyboard] + $params);
            } catch (Exception $e) {
                continue;
            }
        }
    }

    /**
     * @param $id
     * @param null $n
     * @return string
     * @throws VkApiException
     */
    public function getAlias($id, $n = null) { //получить обращение к юзеру или группе
        if (!is_numeric($id)) { //если короткая ссылка
            $obj = $this->request('utils.resolveScreenName', ['screen_name' => $id]); //узнаем, кому принадлежит, сообществу или юзеру
            $id = ($obj["type"] == 'group') ? -$obj['object_id'] : $obj['object_id'];
        }
        if (isset($n)) {
            if (is_string($n)) {
                if ($id < 0)
                    return "@club" . ($id * -1) . "($n)";
                else
                    return "@id{$id}($n)";
            } else {
                if ($id < 0) {
                    $id = -$id;
                    $group_name = $this->request('groups.getById', ['group_id' => $id])[0]['name'];
                    return "@club{$id}({$group_name})";
                } else {
                    $info = $this->userInfo($id);
                    if ($n)
                        return "@id{$id}($info[first_name] $info[last_name])";
                    else
                        return "@id{$id}($info[first_name])";
                }
            }
        } else {
            if ($id < 0)
                return "@club" . ($id * -1);
            else
                return "@id{$id}";
        }
    }

    /**
     * @param null $user_url
     * @param array $scope
     * @return mixed
     * @throws VkApiException
     */
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


    /**
     * @param $chat_id
     * @param $user_id
     * @return bool|null|string
     * @throws VkApiException
     */
    public function isAdmin($user_id, $chat_id) { //возвращает привелегию по id
        try {
            $members = $this->request('messages.getConversationMembers', ['peer_id' => $chat_id])['items'];
        } catch (\Exception $e) {
            throw new VkApiException('Бот не админ в этой беседе, или бота нет в этой беседе');
        }
        foreach ($members as $key) {
            if ($key['member_id'] == $user_id)
                return (isset($key["is_owner"])) ? 'owner' : ((isset($key["is_admin"])) ? 'admin' : false);
        }
        return null;
    }

    /**
     * @param $id
     * @param $message
     * @param array $params
     * @return bool|mixed
     * @throws VkApiException
     */
    public function sendMessage($id, $message, $params = []) {
        if ($id < 1)
            return 0;
        $message = $this->placeholders($id, $message);
        return $this->request('messages.send', ['message' => $message, 'peer_id' => $id] + $params);
    }

    /**
     *
     */
    public function debug() {
        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        echo 'ok';
        $this->debug_mode = 1;
    }

    /**
     * @param $id
     * @param $message
     * @param array $buttons
     * @param bool $inline
     * @param bool $one_time
     * @param array $params
     * @return mixed
     * @throws VkApiException
     */
    public function sendButton($id, $message, $buttons = [], $inline = false, $one_time = False, $params = []) {
        $keyboard = $this->generateKeyboard($buttons, $inline, $one_time);
        $message = $this->placeholders($id, $message);
        return $this->request('messages.send', ['message' => $message, 'peer_id' => $id, 'keyboard' => $keyboard] + $params);
    }

    public function buttonLocation($payload = null) {
        return ['location', $payload];
    }

    public function buttonPayToGroup($group_id, $amount, $description = null, $data = null, $payload = null) {
        return ['vkpay', $payload, 'pay-to-group', $group_id, $amount, $description, $data];
    }

    public function buttonPayToUser($user_id, $amount, $description = null, $payload = null) {
        return ['vkpay', $payload, 'pay-to-user', $user_id, $amount, $description];
    }

    public function buttonDonateToGroup($group_id, $payload = null) {
        return ['vkpay', $payload, 'transfer-to-group', $group_id];
    }

    public function buttonDonateToUser($user_id, $payload = null) {
        return ['vkpay', $payload, 'transfer-to-user', $user_id];
    }

    public function buttonApp($text, $app_id, $owner_id = null, $hash = null, $payload = null) {
        return ['open_app', $payload, $text, $app_id, $owner_id, $hash];
    }
    
    public function buttonOpenLink($text, $link, $payload = null) {
        return ['open_link', $payload, $text, $link];
    }

    public function buttonText($text, $color, $payload = null) {
        return ['text', $payload, $text, $color];
    }

    /**
     * @param array $buttons
     * @param bool $inline
     * @param bool $one_time
     * @return array|false|string
     */
    public function generateKeyboard($buttons = [], $inline = false, $one_time = False) {
        $keyboard = [];
        $i = 0;
        foreach ($buttons as $button_str) {
            $j = 0;
            foreach ($button_str as $button) {
                $keyboard[$i][$j]["action"]["type"] = $button[0];
                if ($button[1] != null)
                    $keyboard[$i][$j]["action"]["payload"] = json_encode($button[1], JSON_UNESCAPED_UNICODE);
                switch ($button[0]) {
                    case 'text': {
                        $color = $this->replaceColor($button[3]);
                        $keyboard[$i][$j]["color"] = $color;
                        $keyboard[$i][$j]["action"]["label"] = $button[2];
                        break;
                    }
                    case 'vkpay': {
                        $keyboard[$i][$j]["action"]["hash"] = "action={$button[2]}";
                        $keyboard[$i][$j]["action"]["hash"] .= ($button[3] < 0) ? "&group_id=".$button[3]*-1 : "&user_id={$button[3]}";
                        $keyboard[$i][$j]["action"]["hash"] .= (isset($button[4])) ? "&amount={$button[4]}" : '';
                        $keyboard[$i][$j]["action"]["hash"] .= (isset($button[5])) ? "&description={$button[5]}" : '';
                        $keyboard[$i][$j]["action"]["hash"] .= (isset($button[6])) ? "&data={$button[6]}" : '';
                        $keyboard[$i][$j]["action"]["hash"] .= "&aid=1";
                        break;
                    }
                    case 'open_app': {
                        $keyboard[$i][$j]["action"]["label"] = $button[2];
                        $keyboard[$i][$j]["action"]["app_id"] = $button[3];
                        if(isset($button[4]))
                            $keyboard[$i][$j]["action"]["owner_id"] = $button[4];
                        if(isset($button[5]))
                            $keyboard[$i][$j]["action"]["hash"] = $button[5];
                        break;
                    }
                    case 'open_link': {
                        $keyboard[$i][$j]["action"]["label"] = $button[2];
                        $keyboard[$i][$j]["action"]["link"] = $button[3];
                        break;
                    }
                }
                $j++;
            }
            $i++;
        }
        $keyboard = ["one_time" => $one_time, "buttons" => $keyboard, 'inline' => $inline];
        $keyboard = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        return $keyboard;
    }

    /**
     * @param $color
     * @return string
     */
    private function replaceColor($color) {
        switch ($color) {
            case 'red':
                $color = 'negative';
                break;
            case 'green':
                $color = 'positive';
                break;
            case 'white':
                $color = 'default';
                break;
            case 'blue':
                $color = 'primary';
                break;
        }
        return $color;
    }

    /**
     * @param $group_url
     * @return mixed
     * @throws VkApiException
     */
    public function groupInfo($group_url) {
        $group_url = preg_replace("!.*?/!", '', $group_url);
        return current($this->request('groups.getById', ["group_ids" => $group_url]));
    }

    /**
     * @param $id
     * @param $local_file_path
     * @param array $params
     * @return mixed
     * @throws VkApiException
     */
    public function sendImage($id, $local_file_path, $params = []) {
        $upload_file = $this->uploadImage($id, $local_file_path);
        return $this->request('messages.send', ['attachment' => "photo" . $upload_file[0]['owner_id'] . "_" . $upload_file[0]['id'], 'peer_id' => $id] + $params);
    }

    /**
     * @param $id
     * @param $local_file_path
     * @return mixed
     * @throws VkApiException
     */
    private function uploadImage($id, $local_file_path) {
        $upload_url = $this->getUploadServerMessages($id, 'photo')['upload_url'];
        for ($i = 0; $i < $this->try_count_resend_file; ++$i) {
            try {
                $answer_vk = json_decode($this->sendFiles($upload_url, $local_file_path, 'photo'), true);
                return $this->savePhoto($answer_vk['photo'], $answer_vk['server'], $answer_vk['hash']);
            } catch (VkApiException $e) {
                sleep(1);
                $exception = json_decode($e->getMessage(), true);
                if ($exception['error']['error_code'] != 121)
                    throw new VkApiException($e->getMessage(), $exception['error']['error_code']);
            }
        }
        $answer_vk = json_decode($this->sendFiles($upload_url, $local_file_path, 'photo'), true);
        return $this->savePhoto($answer_vk['photo'], $answer_vk['server'], $answer_vk['hash']);
    }

    /**
     * @param $peer_id
     * @param string $selector
     * @return mixed|null
     * @throws VkApiException
     */
    private function getUploadServerMessages($peer_id, $selector = 'doc') {
        $result = null;
        if ($selector == 'doc')
            $result = $this->request('docs.getMessagesUploadServer', ['type' => 'doc', 'peer_id' => $peer_id]);
        else if ($selector == 'photo')
            $result = $this->request('photos.getMessagesUploadServer', ['peer_id' => $peer_id]);
        else if ($selector == 'audio_message')
            $result = $this->request('docs.getMessagesUploadServer', ['type' => 'audio_message', 'peer_id' => $peer_id]);
        return $result;
    }

    private function uploadVoice($id, $local_file_path) {
        $upload_url = $this->getUploadServerMessages($id, 'audio_message')['upload_url'];
        $answer_vk = json_decode($this->sendFiles($upload_url, $local_file_path, 'file'), true);
        return $this->saveDocuments($answer_vk['file'], 'voice');
    }

    public function sendVoice($id, $local_file_path, $params = []) {
        $upload_file = $this->uploadVoice($id, $local_file_path);
        return $this->request('messages.send', ['attachment' => "doc" . $upload_file['audio_message']['owner_id'] . "_" . $upload_file['audio_message']['id'], 'peer_id' => $id] + $params);
    }

    /**
     * @param $url
     * @param $local_file_path
     * @param string $type
     * @return mixed
     * @throws VkApiException
     */
    protected function sendFiles($url, $local_file_path, $type = 'file') {
        $post_fields = [
            $type => new CURLFile(realpath($local_file_path))
        ];

        for ($i = 0; $i < $this->try_count_resend_file; ++$i) {

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type:multipart/form-data"
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $output = curl_exec($ch);
            if ($output != '')
                break;
            else
                sleep(1);
        }
        if ($output == '')
            throw new VkApiException('Не удалось загрузить файл на сервер');
        return $output;
    }

    /**
     * @param $photo
     * @param $server
     * @param $hash
     * @return mixed
     * @throws VkApiException
     */
    private function savePhoto($photo, $server, $hash) {
        return $this->request('photos.saveMessagesPhoto', ['photo' => $photo, 'server' => $server, 'hash' => $hash]);
    }

    /**
     * @param $groupID
     * @param $local_file_path
     * @param null $title
     * @return mixed
     *
     * @throws VkApiException
     */
    public function uploadDocsGroup($groupID, $local_file_path, $title = null) {
        return $this->uploadDocs($groupID, $local_file_path, $title);
    }

    /**
     * @param $id
     * @param $local_file_path
     * @param null $title
     * @return mixed
     * @throws VkApiException
     */
    private function uploadDocs($id, $local_file_path, $title = null) {
        if (!isset($title))
            $title = preg_replace("!.*?/!", '', $local_file_path);
        $upload_url = $this->getUploadServerPost($id)['upload_url'];
        $answer_vk = json_decode($this->sendFiles($upload_url, $local_file_path), true);
        $upload_file = $this->saveDocuments($answer_vk['file'], $title);
        return $upload_file;
    }

    /**
     * @param array $peer_id
     * @return mixed
     * @throws VkApiException
     */
    private function getUploadServerPost($peer_id = []) {
        if ($peer_id < 0)
            $peer_id = ['group_id' => $peer_id * -1];
        else
            $peer_id = [];
        $result = $this->request('docs.getUploadServer', $peer_id);
        return $result;
    }

    /**
     * @param $file
     * @param $title
     * @return mixed
     * @throws VkApiException
     */
    private function saveDocuments($file, $title) {
        return $this->request('docs.save', ['file' => $file, 'title' => $title]);
    }

    /**
     * @param $id
     * @param $local_file_path
     * @param null $title
     * @param array $params
     * @return bool|mixed
     * @throws VkApiException
     */
    public function sendDocMessage($id, $local_file_path, $title = null, $params = []) {
        $upload_file = current($this->uploadDocsMessages($id, $local_file_path, $title));
        if ($id != 0 and $id != '0') {
            return $this->request('messages.send', ['attachment' => "doc" . $upload_file['owner_id'] . "_" . $upload_file['id'], 'peer_id' => $id] + $params);
        } else {
            return true;
        }
    }

    /**
     * @param $id
     * @param $local_file_path
     * @param null $title
     * @return mixed
     * @throws VkApiException
     */
    private function uploadDocsMessages($id, $local_file_path, $title = null) {
        if (!isset($title))
            $title = preg_replace("!.*?/!", '', $local_file_path);
        $upload_url = $this->getUploadServerMessages($id)['upload_url'];
        $answer_vk = json_decode($this->sendFiles($upload_url, $local_file_path), true);
        $upload_file = $this->saveDocuments($answer_vk['file'], $title);
        return $upload_file;
    }

    /**
     * @param $id
     * @param array $message
     * @param array $props
     * @param array $media
     * @return mixed
     * @throws VkApiException
     */
    public function createPost($id, $message = [], $props = [], $media = []) {
        $send_attachment = [];

        foreach ($media as $selector => $massive) {
            switch ($selector) {
                case "images":
                    foreach ($massive as $image) {
                        $upload_url = $this->getWallUploadServer($id);
                        for ($i = 0; $i <= $this->try_count_resend_file; ++$i) {
                            try {
                                $answer_vk = json_decode($this->sendFiles($upload_url['upload_url'], $image, 'photo'), true);
                                $upload_file = $this->savePhotoWall($answer_vk['photo'], $answer_vk['server'], $answer_vk['hash'], $id);
                                $send_attachment[] = "photo" . $upload_file[0]['owner_id'] . "_" . $upload_file[0]['id'];
                                break;
                            } catch (VkApiException $e) {
                                if ($i == $this->try_count_resend_file)
                                    throw new VkApiException($e->getMessage(), $e->getCode());
                                sleep(1);
                                $exception = json_decode($e->getMessage(), true);
                                if ($exception['error']['error_code'] != 121)
                                    throw new VkApiException($e->getMessage(), $e->getCode());
                            }
                        }
                    }
                    break;
                case "docs":
                    foreach ($massive as $docs) {
                        $upload_file = $this->uploadDocsUser($docs);
                        if (isset($upload_file['type']))
                            $upload_file = $upload_file[$upload_file['type']];
                        else
                            $upload_file = current($upload_file);
                        $send_attachment[] = "doc" . $upload_file['owner_id'] . "_" . $upload_file['id'];
                    }
                    break;
                case "other":
                    break;
            }
        }
        if (count($send_attachment) != 0)
            $send_attachment = ["attachment" => join(',', $send_attachment)];
        if (is_string($message))
            $message = ['message' => $message];
        return $this->request('wall.post', ['owner_id' => $id] + $message + $props + $send_attachment);
    }

    /**
     * @param $owner_id , $post_id, $message
     * @param $post_id
     * @param $message
     * @return mixed
     * @throws VkApiException
     */
    public function sendWallComment($owner_id, $post_id, $message) {
        return $this->request('wall.createComment', ['owner_id' => $owner_id, 'post_id' => $post_id, 'message' => $message]);
    }

    /**
     * @param $id
     * @return mixed
     * @throws VkApiException
     */
    private function getWallUploadServer($id) {
        if ($id < 0) {
            $id *= -1;
            return $this->request('photos.getWallUploadServer', ['group_id' => $id]);
        } else {
            return $this->request('photos.getWallUploadServer', ['user_id' => $id]);
        }
    }

    /**
     * @param $photo
     * @param $server
     * @param $hash
     * @param $id
     * @return mixed
     * @throws VkApiException
     */
    private function savePhotoWall($photo, $server, $hash, $id) {
        if ($id < 0) {
            $id *= -1;
            return $this->request('photos.saveWallPhoto', ['photo' => $photo, 'server' => $server, 'hash' => $hash, 'group_id' => $id]);
        } else {
            return $this->request('photos.saveWallPhoto', ['photo' => $photo, 'server' => $server, 'hash' => $hash, 'user_id' => $id]);
        }
    }

    /**
     * @param $local_file_path
     * @param null $title
     * @return mixed
     *
     * @throws VkApiException
     */
    public function uploadDocsUser($local_file_path, $title = null) {
        return $this->uploadDocs([], $local_file_path, $title);
    }

    /**
     * @param $id
     * @param array $message
     * @param array $props
     * @param array $media
     * @param array $keyboard
     * @return mixed
     * @throws VkApiException
     */
    public function createMessages($id, $message = [], $props = [], $media = [], $keyboard = []) {
        if ($id < 1)
            return 0;
        $send_attachment = [];

        foreach ($media as $selector => $massiv) {
            switch ($selector) {
                case "images":
                    foreach ($massiv as $image) {
                        $upload_file = $upload_file = $this->uploadImage($id, $image);
                        $send_attachment[] = "photo" . $upload_file[0]['owner_id'] . "_" . $upload_file[0]['id'];
                    }
                    break;
                case "docs":
                    foreach ($massiv as $document) {
                        $upload_file = $this->uploadDocsMessages($id, $document['path'], $document['title']);
                        if (isset($upload_file['type']))
                            $upload_file = $upload_file[$upload_file['type']];
                        else
                            $upload_file = current($upload_file);
                        $send_attachment[] = "doc" . $upload_file['owner_id'] . "_" . $upload_file['id'];
                    }
                    break;
                case "voice":
                    foreach ($massiv as $voice) {
                        $upload_file = $this->uploadVoice($id, $voice);
                        $send_attachment[] = "doc" . $upload_file['audio_message']['owner_id'] . "_" . $upload_file['audio_message']['id'];
                    }
                    break;
                case "other":
                    break;
            }
        }
        if (count($send_attachment) != 0)
            $send_attachment = ["attachment" => join(',', $send_attachment)];
        if (is_string($message))
            $message = ['message' => $message];
        if ($keyboard != [])
            $keyboard = ['keyboard' => $this->generateKeyboard($keyboard['keyboard'], $keyboard['inline'], $keyboard['one_time'])];
        return $this->request('messages.send', ['peer_id' => $id] + $message + $props + $send_attachment + $keyboard);
    }

    /**
     * @param array $id
     * @param int $extended
     * @param array $props
     * @return mixed
     * @throws VkApiException
     */
    public function getGroupsUser($id = [], $extended = 1, $props = []) {
        if (is_numeric($id))
            $id = ['user_id' => $id];
        if (!is_array($props))
            $props = [];
        if ($extended == 1)
            $extended = ['extended' => 1];
        else
            $extended = [];
        return $this->request('groups.get', $id + $props + $extended);
    }

    /**
     * @param $var
     * @throws VkApiException
     */
    public function setTryCountResendFile($var) {
        if (is_integer($var))
            $this->try_count_resend_file = $var;
        else
            throw new VkApiException("Параметр должен быть числовым");
    }

    /**
     * @param $var
     * @throws VkApiException
     */
    public function setRequestIgnoreError($var) {
        if (is_array($var))
            $this->request_ignore_error = $var;
        else if (is_integer($var))
            $this->request_ignore_error = [$var];
        else
            throw new VkApiException("Параметр должен быть числовым либо массивом");
    }

    /**
     * @param $id
     * @return mixed
     */
    public function dateRegistration($id) {
        $site = file_get_contents("https://vk.ru/foaf.php?id={$id}");
        preg_match('<ya:created dc:date="(.*?)">', $site, $data);
        $data = explode('T', $data[1]);
        $date = date("d.m.Y", strtotime($data[0]));
        $time = mb_substr($data[1], 0, 8);
        return "$time $date";
    }

    /**
     * @return array
     */
    protected function copyAllDataclass() {
        return [$this->token, $this->version, $this->auth, $this->request_ignore_error, $this->try_count_resend_file];
    }

    /**
     * @param $id_vk_vars
     */
    protected function setAllDataclass($id_vk_vars) {
        list($this->token, $this->version, $this->auth, $this->request_ignore_error, $this->try_count_resend_file) = $id_vk_vars;
    }
}
