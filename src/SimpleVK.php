<?php

namespace DigitalStars\SimpleVK;

use Exception;
use DigitalStars\SimpleVK\Internal\UniqueEventHandler;

require_once('config_simplevk.php');

class SimpleVK {
    use ErrorHandler, Request;

    protected $version;
    public $data = [];
    protected $data_backup = [];
    protected $api_url = 'https://api.vk.ru/method/';
    protected $token;
    private static $debug_mode = false;
    private static $retry_requests_processing = false;
    protected $auth = null;
    private $is_test_len_str = true;
    protected $group_id = null;
    public $time_checker = null;

    protected static ?\FFI $ffi = null;

    public static function create($token, $version, $also_version = null, $data = null) {
        return new self($token, $version, $also_version, $data);
    }

    public function __construct($token, $version, $also_version = null, $data = null) {

        if (!self::$retry_requests_processing &&
            ((function_exists('getallheaders') && isset(getallheaders()['X-Retry-Counter'])) ||
                isset($_SERVER['HTTP_X_RETRY_COUNTER']))) {
            exit('ok');
        }

        if(!self::$ffi && extension_loaded("ffi")) {
            if (!in_array(ini_get('ffi.enable'), ['true', '1'], true)) {
                @ini_set('ffi.enable', 'true');
                if (!in_array(ini_get('ffi.enable'), ['true', '1'], true)) {
                    throw new \RuntimeException("FFI is disabled in PHP configuration. Set ffi.enable = true");
                }
            }
            if (PHP_OS === 'WINNT') {
                $library_path = dirname(__DIR__) . '/bin/convert_to_html_entities.dll';

                // Проверяем, содержит ли путь что-то кроме базовых ASCII символов
                if (!str_contains($library_path, '~') && preg_match('/[^\x20-\x7E]/', $library_path)) {
                    // Пытаемся получить короткое 8.3 имя через команду cmd
                    // Это трюк, который работает, если exec() разрешен и 8.3 имена включены в системе
                    $command = 'for %I in ("' . $library_path . '") do @echo %~sI';
                    $short_path = exec($command);

                    if ($short_path && file_exists($short_path)) {
                        $library_path = $short_path;
                    }
                }

                if ($library_path && file_exists($library_path)) {
                    try {
                        self::$ffi = \FFI::cdef(
                            "char* convert_to_html_entities(const char* input);
                void free_converted_string(char* result);", $library_path
                        );
                    } catch (\FFI\Exception $e) {
                        $message = "FFI доступен, но не удалось загрузить библиотеку 'convert_to_html_entities.dll'.\n";
                        $message .= "Возможно в пути есть кириллица или пробелы. Путь к библиотеке:\n";
                        $message .= "{$library_path}\n";

                        trigger_error($message, E_USER_WARNING);
                        self::$ffi = null;
                    }
                }
            } elseif (PHP_OS === 'Linux') {
                $path = __DIR__."/../bin/libconvert_to_html_entities.so";
                self::$ffi = \FFI::cdef(
                    "char* convert_to_html_entities(const char* input);
                    void free_converted_string(char* result);", $path
                );
            }
        }

        if ((double)($version) <  5.139) {
            throw new Exception('SimpleVK3 работает с VK API версиями 5.139 или выше. Вы запустили с v' . $version);
        }

        $this->processAuth($token, $version, $also_version);
        if($data) {
            $this->data = json_decode($data, 1);
        } else {
            $this->data = json_decode(file_get_contents('php://input'), 1);
        }

        $this->data_backup = $this->data;

        if (isset($this->data['type']) && $this->data['type'] != 'confirmation') {
            if (self::$debug_mode) {
                ini_set('display_errors', 1);
                ini_set('error_reporting', E_ALL);
            } else {
                $this->sendOK();
                $this->setupBackgroundProcessing();
            }

            $is_dublicated = UniqueEventHandler::addEventToCache($this->data);
            if($is_dublicated) {
                exit();
            }

            if (isset($this->data['object']['message']) && $this->data['type'] == 'message_new') {
                $this->data['object'] = $this->data['object']['message'];
            }
        }
    }

    public function __call($method, $args = []) {
        $method = str_replace("_", ".", $method);
        $args = (empty($args)) ? $args : $args[0];
        return $this->request($method, $args);
    }

    public static function retryRequestsProcessing($flag = true) {
        self::$retry_requests_processing = $flag;
    }

    public static function disableSendOK($flag = true) {
        self::$debug_mode = $flag;
    }

    public function setConfirm($str) {
        if (isset($this->data['type']) && $this->data['type'] == 'confirmation') {
            exit($str);
        }
        return $this;
    }

    public function setSecret($str) {
        if (isset($this->data['secret']) && $this->data['secret'] == $str) {
            return $this;
        }
        exit('security error');
    }

    public function reply($message) {
        $this->initPeerID($id);
        $result = $this->request('messages.send', ['peer_ids' => $id, 'message' => $message, 'random_id' => 0]);
        return $result[0]['conversation_message_id'] ?? null;
    }

    public function msg($text = null) {
        return Message::create($this)->text($text);
    }

    public function isAdmin($user_id, $chat_id) { //возвращает привилегию по id
        try {
            $members = $this->request('messages.getConversationMembers', ['peer_id' => $chat_id])['items'];
        } catch (Exception $e) {
            throw new SimpleVkException(0, 'Бот не админ в этой беседе, или бота нет в этой беседе');
        }
        foreach ($members as $key) {
            if ($key['member_id'] == $user_id)
                return (isset($key["is_owner"])) ? 'owner' : ((isset($key["is_admin"])) ? 'admin' : false);
        }
        return null;
    }

    public function initPeerID(&$id) {
        $id = $this->data['object']['peer_id'] ?? null;
        return $this;
    }

    public function initText(&$text) {
        $text = $this->data['object']['text'] ?? null;
        return $this;
    }

    public function initPayload(&$payload) {
        $payload = $this->getPayload();
        return $this;
    }

    public function initUserID(&$user_id) {
        $user_id =
            $this->data['object']['deleter_id'] ?? // кто удалил коммент для wall_reply_delete / market_comment_delete
            $this->data['object']['liker_id'] ?? //кто поставил лайк like_add / like_remove
            $this->data['object']['from_id'] ??
            $this->data['object']['user_id'] ??
            $this->data['object']['owner_id'] ?? null;
        return $this;
    }

    public function initType(&$type) {
        $type = $this->data['type'] ?? null;
        return $this;
    }

    public function initData(&$data) {
        $data = $this->data_backup;
        return $this;
    }

    public function initID(&$mid) {
        $mid = $this->data['object']['id'] ?? null;
        return $this;
    }

    public function initConversationMsgID(&$cmid) {
        $cmid = $this->data['object']['conversation_message_id'] ?? null;
        return $this;
    }

    public function getAttachments() {
        $data = $this->data;
        return null;
        if (!isset($data['object']['attachments']))
            return null;
        $result = [];
        if (isset($data['object']['attachments']['attach1_type'])) //TODO временная заглушка для user longpoll
            return null;
        foreach ($data['object']['attachments'] as $key => $attachment) {
            if ($key == 'attach1_type') //TODO временная заглушка для user longpoll
                return null;
            $type = $attachment['type'];
            $attachment = $attachment[$type];
            if (isset($attachment['sizes'])) {
                $preview = $attachment['sizes'];
                unset($attachment['sizes']);
            } else if (isset($attachment['preview']))
                $preview = $attachment['preview']['photo']['sizes'];
            else
                $preview = null;
            if ($preview) {
                $previews_result = [];
                foreach ($preview as $item) {
                    $previews_result[$item['type']] = $item;
                }
                if (empty($attachment['url']))
                    $attachment['url'] = end($previews_result)['url'];
                $attachment['preview'] = $previews_result;
            }
            $result[$type][] = $attachment;
        }
        return $result;
    }

    public function getAffectedUsers($use_category = false, $category = ['fwd', 'reply', 'mention', 'url']) {
        $affected_users = [];
        $category = is_array($category) ? $category : [$category];

        if(in_array('fwd', $category)) {
            $fwd = $this->data['object']['fwd_messages'] ?? null;
            if ($fwd) {
                foreach ($fwd as $value) {
                    $affected_users['fwd'][] = $value['from_id'];
                    if (preg_match_all("/\[(id|club|public)([0-9]*)\|[^\]]*\]/", $value['text'], $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $key => $match) {
                            $affected_users['fwd'][] = (int)(($match[1] == 'id') ? $match[2] : -$match[2]);
                        }
                    }
                }
            }
        }

        if(in_array('reply', $category)) {
            $reply_from_id = $this->data['object']['reply_message']['from_id'] ?? null;
            if($reply_from_id) {
                $affected_users['reply'] = [$reply_from_id];
                if (preg_match_all("/\[(id|club|public)([0-9]*)\|[^\]]*\]/", $this->data['object']['reply_message']['text'], $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $key => $value) {
                        $affected_users['reply'][] = (int)(($value[1] == 'id') ? $value[2] : -$value[2]);
                    }
                }
            }
        }

        $this->initText($msg);

        if(in_array('mention', $category)) {
            if (preg_match_all("/\[(id|club|public)([0-9]*)\|[^\]]*\]/", $msg, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $key => $value) {
                    $affected_users['mention'][] = (int)(($value[1] == 'id') ? $value[2] : -$value[2]);
                }
            }
        }

        if (in_array('url', $category)) {
            if (preg_match_all("/vk.ru\/(?:id([0-9]+)|([a-z0-9_.]+))/", $msg, $matches)) {
                $ids = array_filter(array_merge($matches[1], $matches[2]));

                if (!empty($ids)) {
                    $user_ids = $this->userInfo($ids);
                    $user_ids = isset($user_ids['id']) ? [$user_ids] : $user_ids;
                    $affected_users['url'] = array_column($user_ids, 'id') ?? [];

                    // Удаляем уже обработанные идентификаторы пользователей из списка
                    $group_ids = array_values(array_diff($ids, $affected_users['url']));

                    // Обрабатываем оставшиеся идентификаторы как группы
                    if (!empty($group_ids)) {
                        $group_ids = $this->groupInfo($group_ids);
                        $group_ids = isset($group_ids['id']) ? [$group_ids] : $group_ids;
                        $group_ids = array_column($group_ids, 'id') ?? [];
                        $group_ids = array_map(static fn($el) => $el * -1, $group_ids);

                        $affected_users['url'] = array_merge($affected_users['url'], $group_ids);
                    }

                    if (empty($affected_users['url'])) {
                        $affected_users['url'] = null;
                    }
                } else {
                    $affected_users['url'] = null;
                }
            }
        }

        if (!$use_category) {
            return array_values(array_filter(array_merge(...array_values($affected_users))));
        }

        return array_filter($affected_users);
    }

    public function initVars(&$peer_id = null, &$user_id = null, &$type = null, &$message = null, &$payload = null, &$id = null, &$attachments = null) {
        $data = $this->data;
        $type = $data['type'] ?? null;
        $peer_id = $data['object']['peer_id'] ?? null;
        $message = $data['object']['text'] ?? null;
        $this->initUserID($user_id);
        $id = $data['object']['id'] ?? null;
        $payload = $this->getPayload();
        $attachments = $this->getAttachments();
        return $this->data_backup;
    }

    public function clientSupport(&$keyboard = null, &$inline = null, &$carousel = null, &$button_actions = null, &$lang_id = null) {
        $data = $this->data_backup['object']['client_info'] ?? null;
        $keyboard = $data['keyboard'] ?? null;
        $inline = $data['inline_keyboard'] ?? null;
        $carousel = $data['carousel'] ?? null;
        $button_actions = $data['button_actions'] ?? null;
        $lang_id = $data['lang_id'] ?? null;
        return $data;
    }

    public function sendAllDialogs(Message $message) {
        $ids = [];
        $i = 0;
        $count = 0;
        print "Начинаю рассылку\n";
        $members = $this->request('messages.getConversations', ['count' => 1])['count'];
        foreach ($this->getAllDialogs() as $dialog) {
            if ($dialog['conversation']['can_write']['allowed']) {
                $user_id = $dialog['conversation']['peer']['id'];
                if ($user_id < 2e9) {
                    $ids[] = $user_id;
                    $i++;
                }
            }
            if ($i == 100) {
                $return = $message->send($ids);
                $i = 0;
                $ids = [];
                $current_count = count(array_column($return, 'message_id'));
                $count += $current_count;
                print "Отправлено $count/$members" . PHP_EOL;
            }
        }
        $return = $message->send($ids);
        $current_count = count(array_column($return, 'message_id'));
        $count += $current_count;
        print "Всего было отправлено $count/$members сообщений" . PHP_EOL;
        print "Запретили отправлять сообщения " . ($members - $count) . " человек(либо это были чаты)";
    }

    public function sendAllChats(Message $message) {
        $message->uploadAllImages();
        $count = 0;
        print "Начинаю рассылку\n";
        for ($i = 1; ; $i += 100) {
            $return = $message->send(range(2e9 + $i, 2e9 + $i + 99));
            $current_count = count(array_column($return, 'message_id'));
            $count += $current_count;
            print "Отправлено $count" . PHP_EOL;
            if ($current_count != 100) {
                print "Всего было разослано в $count бесед";
                break;
            }
        }
    }

    public function eventAnswerSnackbar($text) {
        $this->checkTypeEvent();
        $this->request('messages.sendMessageEventAnswer', [
            'event_id' => $this->data['object']['event_id'],
            'user_id' => $this->data['object']['user_id'],
            'peer_id' => $this->data['object']['peer_id'],
            'event_data' => json_encode([
                'type' => 'show_snackbar',
                'text' => $text
            ], JSON_THROW_ON_ERROR)
        ]);
    }

    public function eventAnswerEmpty() { //для прекращения спиннера
        $this->checkTypeEvent();
        $this->request('messages.sendMessageEventAnswer', [
            'event_id' => $this->data['object']['event_id'],
            'user_id' => $this->data['object']['user_id'],
            'peer_id' => $this->data['object']['peer_id']
        ]);
    }

    public function eventAnswerOpenLink($link) {
        $this->checkTypeEvent();
        $this->request('messages.sendMessageEventAnswer', [
            'event_id' => $this->data['object']['event_id'],
            'user_id' => $this->data['object']['user_id'],
            'peer_id' => $this->data['object']['peer_id'],
            'event_data' => json_encode([
                'type' => 'open_link',
                'link' => $link
            ], JSON_THROW_ON_ERROR)
        ]);
    }

    public function eventAnswerOpenApp($app_id, $owner_id = null, $hash = null) {
        $this->checkTypeEvent();
        $this->request('messages.sendMessageEventAnswer', [
            'event_id' => $this->data['object']['event_id'],
            'user_id' => $this->data['object']['user_id'],
            'peer_id' => $this->data['object']['peer_id'],
            'event_data' => json_encode([
                'type' => 'open_app',
                'app_id' => $app_id,
                'owner_id' => $owner_id,
                'hash' => $hash
            ], JSON_THROW_ON_ERROR)
        ]);
    }

    public function dateRegistration($id) {
        $response = $this->request('restore.disablePageInit', ['user_id' => $id], dont_use_token: true);
        return isset($response['user']['date_created'])
            ? date("H:i:s d.m.Y", $response['user']['date_created'])
            : null;
//        $site = file_get_contents("https://vk.ru/foaf.php?id={$id}");
//        preg_match('<ya:created dc:date="(.*?)">', $site, $data);
//        $data = explode('T', $data[1]);
//        $date = date("d.m.Y", strtotime($data[0]));
//        $time = mb_substr($data[1], 0, 8);
//        return "$time $date";
    }

    public function buttonLocation($payload = null) {
        return ['location', $payload, null];
    }

    public function buttonOpenLink($link, $label = 'Открыть', $payload = null) {
        return ['open_link', $payload, $link, $label];
    }

    public function buttonPayToGroup($group_id, $amount, $description = null, $data = null, $payload = null) {
        return ['vkpay', $payload, 'pay-to-group', $group_id, $amount, urlencode($description), $data];
    }

    public function buttonPayToUser($user_id, $amount, $description = null, $payload = null) {
        return ['vkpay', $payload, 'pay-to-user', $user_id, $amount, urlencode($description)];
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

    public function buttonText($text, $color = 'white', $payload = null) {
        return ['text', $payload, $text, self::$color_replacer[$color]];
    }

    public function buttonCallback($text, $color = 'white', $payload = null) {
        return ['callback', $payload, $text, self::$color_replacer[$color]];
    }

    static $color_replacer = [
        'blue' => 'primary',
        'white' => 'default',
        'red' => 'negative',
        'green' => 'positive'
    ];

    public function json_online($data = null) {
        if (is_null($data))
            $data = $this->data;
        $json = is_array($data) ? json_encode($data) : $data;
        $name = time() . random_int(-2147483648, 2147483647);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($json) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type:application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $name, 'data' => $json]));
        }
        curl_setopt($ch, CURLOPT_URL, 'https://jsoneditoronline.herokuapp.com/v1/docs/');
        $result = json_decode(curl_exec($ch), True);
        curl_close($ch);
        return 'https://jsoneditoronline.org/?id=' . $result['id'];
    }

    public function userInfo($users_url = null, $fields = null, $name_case = 'nom') {
        $users_url = is_array($users_url) ? $users_url : [$users_url];
        $fields = is_array($fields) ? $fields : [$fields];
        $user_ids = array_map([__CLASS__, 'parseUrl'], $users_url);
        $param_ids = ['user_ids' => implode(',', $user_ids)];
        if ($param_ids['user_ids'] == '') {
            $param_ids = [];
        }
        $scope = is_array($fields) ? ["fields" => implode(",", $fields)] : [];
        $case = ['name_case' => $name_case];

        try {
            $result = $this->request('users.get', $param_ids + $scope + $case);
            if (isset($result['error']) || !$result) {
                return $result;
            }
            return count($result) == 1 ? $result[0] : $result;
        } catch (Exception $e) {
            return false;
        }
    }

    public function groupInfo($groups_url = null, $fields = null) {
        $groups_url = is_array($groups_url) ? $groups_url : [$groups_url];
        $fields = is_array($fields) ? $fields : [$fields];
        $group_ids = array_map([__CLASS__, 'parseUrl'], $groups_url);
        $param_ids = ['group_ids' => implode(',', $group_ids)];
        if ($param_ids['group_ids'] == '') {
            $param_ids = [];
        }
        $fields = ["fields" => implode(",", $fields)];

        try {
            $result = $this->request('groups.getById', $param_ids + $fields);
            if (isset($result['error'])) {
                return $result;
            }
            return count($result) == 1 ? $result['groups'][0] : $result['groups'];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ограничить отправку сообщений пользователю/ям в беседе
     * @param int $seconds количество секунд мута или 0 для бесконечного
     * @param array|int $user_ids По умолчанию user_id из события
     * @param int|null $peer_id По умолчанию peer_id из события
     * @return array|null
     */
    public function setMute(int $seconds = 0, array|int $user_ids = [], ?int $peer_id = null): ?array {
        $this->initPeerID($from_peer_id)->initUserID($from_user_id);

        if($seconds < 0) {
            trigger_error(
                "Количество секунд мута должно быть больше или равно 0.",
                E_USER_WARNING
            );

            return null;
        }

        if(!$from_peer_id && !$from_user_id && !$peer_id && !$user_ids) {
            trigger_error("Попытка вызова setMute без параметров, при отсутствии в событии от ВК peer_id и user_id.", E_USER_WARNING);
            return null;
        }

        $for = ($seconds == 0) ? [] : ['for' => $seconds];
        $user_ids = is_array($user_ids) ? $user_ids : [$user_ids];
        $member_ids = empty($user_ids) ? ['member_ids' => $from_user_id] : ['member_ids' => implode(',', $user_ids)];
        $peer_id_param = $peer_id ? ['peer_id' => $peer_id] : ['peer_id' => $from_peer_id];

        return $this->request('messages.changeConversationMemberRestrictions',
            ['action' => 'ro'] + $peer_id_param + $for + $member_ids);
    }

    /**
     * Снять ограничия отправки сообщений пользователю/ям в беседе
     * @param array|int $user_ids По умолчанию user_id из события
     * @param int|null $peer_id По умолчанию peer_id из события
     * @return array|null
     */
    public function unsetMute(array|int $user_ids = [], ?int $peer_id = null): ?array {
        $this->initPeerID($from_peer_id)->initUserID($from_user_id);

        if(!$from_peer_id && !$from_user_id && !$peer_id && !$user_ids) {
            trigger_error("Попытка вызова unsetMute без параметров, при отсутствии в событии от ВК peer_id и user_id.", E_USER_WARNING);
            return null;
        }

        $user_ids = is_array($user_ids) ? $user_ids : [$user_ids];
        $member_ids = empty($user_ids) ?  ['member_ids' => $from_user_id] : ['member_ids' => implode(',', $user_ids)];
        $peer_id_param = $peer_id ? ['peer_id' => $peer_id] : ['peer_id' => $from_peer_id];

        return $this->request('messages.changeConversationMemberRestrictions',
            ['action' => 'rw'] + $peer_id_param + $member_ids);
    }

    public function getAllDialogs($extended = 0, $filter = 'all', $fields = null) {
        for ($count_all = 0, $offset = 0, $last_id = []; $offset <= $count_all; $offset += 199) {
            $members = $this->request('messages.getConversations', $last_id + [
                    'count' => 200,
                    'filter' => $filter,
                    'extended' => $extended,
                    'fields' => (is_array($fields) ? join(',', $fields) : '')]);
            if ($count_all == 0)
                $count_all = $members['count'];
            if (empty($members['items']))
                break;
            foreach ($members['items'] as $item) {
                if (($last_id['start_message_id'] ?? 0) == $item['last_message']['id']) {
                    continue;
                } else
                    $last_id['start_message_id'] = $item['last_message']['id'];
                yield $item;
            }
        }
    }

    public function getAllComments($owner_id_or_url, $post_id = null, $sort = 'asc', $extended = 0, $fields = null) {
        if (!is_numeric($owner_id_or_url) && is_null($post_id)) {
            if (preg_match("!(-?\d+)_(\d+)!", $owner_id_or_url, $matches)) {
                $owner_id = $matches[1];
                $post_id = $matches[2];
            } else {
                throw new SimpleVkException(0, "Передайте 2 параметра (id пользователя, id поста), или корректную ссылку на пост");
            }
        }
        for ($count_all = 0, $offset = 0, $last_id = []; $offset <= $count_all; $offset += 99) {
            $members = $this->request('wall.getComments', $last_id + [
                    'count' => 100,
                    'owner_id' => $owner_id,
                    'post_id' => $post_id,
                    'extended' => $extended,
                    'sort' => $sort,
                    'fields' => (is_array($fields) ? join(',', $fields) : '')]);
            if ($count_all == 0)
                $count_all = $members['count'];
            if (empty($members['items']))
                break;
            foreach ($members['items'] as $item) {
                if (($last_id['start_comment_id'] ?? 0) == $item['id']) {
                    continue;
                } else
                    $last_id['start_comment_id'] = $item['id'];
                yield $item;
            }
        }
    }

    public function getAllMembers($group_id = null, $sort = null, $filter = null, $fields = null) {
        if (is_null($group_id))
            $group_id = $this->groupInfo()['id'];
        return $this->generatorRequest('groups.getMembers', [
                'fields' => (is_array($fields) ? join(',', $fields) : ''),
                'group_id' => $group_id]
            + ($filter ? ['filter' => $filter] : [])
            + ($sort ? ['sort' => $sort] : []), 1000);
    }

    public function getAllGroupsFromUser($user_id = null, $extended = 0, $filter = null, $fields = null) {
        $extended = (!is_null($fields) || $extended);
        return $this->generatorRequest('groups.get', [
                'extended' => $extended]
            + ($filter ? ['filter' => $filter] : [])
            + ($fields ? ['fields' => $fields] : [])
            + ($user_id ? ['user_id' => $user_id] : []), 1000);
    }

    public function getAllWalls($id = null, $extended = 0, $filter = null, $fields = null) {
        $extended = (!is_null($fields) || $extended);
        return $this->generatorRequest('wall.get', [
                'extended' => $extended]
            + ($filter ? ['filter' => $filter] : [])
            + ($fields ? ['fields' => $fields] : [])
            + ($id ? ['owner_id' => $id] : []), 100);
    }

    public function generatorRequest($method, $params, $count = 200) {
        for ($count_all = 0, $offset = 0; $offset <= $count_all; $offset += $count) {
            $result = $this->request($method, $params + ['offset' => $offset, 'count' => $count]);
            if ($count_all == 0) {
                $count_all = $result['count'];
            }
            if (!isset($result['items'])) {
                yield $result;
                continue;
            }

            foreach ($result['items'] as $item) {
                yield $item;
            }
        }
    }

    public function group($id = null) {
        $this->group_id = $id;
        return $this;
    }

    private function convertMessageToHtmlEntities($message) {
        if (self::$ffi !== null) {
            $result = self::$ffi->convert_to_html_entities($message);
            $message_with_html_entities = \FFI::string($result);
            self::$ffi->free_converted_string($result);
            return $message_with_html_entities;
        }
        return $this->convertToHtmlEntities($message);
    }

    /**
     * Преобразует строку в HTML-сущности для правильного подсчета в VK API.
     * Обрабатывает только эмодзи и символы за пределами BMP (U+10000 и выше).
     * Специальные HTML-символы (например, <, >, &) обрабатываются как обычно.
     */
    protected function convertToHtmlEntities($string) {
        if (empty($string)) {
            return "";
        }

        // Шаг 1: Экранируем базовые HTML-символы. Это обязательно и должно идти первым.
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Шаг 2: Определяем карту преобразования только для символов за пределами BMP (U+10000 и выше).
        // Это как раз диапазон, где находятся почти все эмодзи.
        $convmap = [
            0x10000, 0x10FFFF,  // Диапазон кодовых точек (от U+10000 до U+10FFFF)
            0, 0xFFFFF         // Смещение и маска (стандартные значения для преобразования в сущности)
        ];

        // Шаг 3: Преобразуем только указанный диапазон.
        // Кириллица и другие символы из BMP останутся без изменений
        return mb_encode_numericentity($string, $convmap, 'UTF-8');
    }

    /**
     * Разделяет длинную строку, закодированную в HTML-сущности, на несколько частей,
     * гарантируя целостность сущностей и учитывая разрывы строк и разрывы слов
     * в пределах последних символов части.
     *
     * @param string $encodedHtml      Исходная строка, содержащая HTML-сущности, которую нужно разделить.
     * @param int $maxLength        Максимальная длина каждой части. По умолчанию 4096 символов.
     * @param int $tailSearchLimit  Количество символов в конце части, в пределах которых
     *                                 будет производиться поиск разрывов (например, \n или < br>).
     * @param int $spaceSearchLimit Количество символов в конце части, в пределах которых
     *                                 будет производиться поиск пробелов для разделения в случае разрыва.
     *
     * @return string[] Массив строк, каждая из которых является частью исходного текста.
     */
    protected function splitLongMessages(
        string $encodedHtml,
        int $maxLength = 4096,
        int $tailSearchLimit = 150,
        int $spaceSearchLimit = 25
    ): array {
        $text_len = mb_strlen($encodedHtml, 'UTF-8');
        $parts = [];
        $start = 0;

        while ($start < $text_len) {
            $currentPart = mb_substr($encodedHtml, $start, $maxLength, 'UTF-8');
            $isSplitByMaxLength = (mb_strlen($currentPart, 'UTF-8') === $maxLength);

            // Проверяем только последние 9 символов текущей части
            $lastChunk = mb_substr($currentPart, -9, null, 'UTF-8');
            $lastAmpersandPos = mb_strrpos($lastChunk, '&', 0, 'UTF-8');
            $lastSemicolonPos = mb_strrpos($lastChunk, ';', 0, 'UTF-8');

            if ($lastAmpersandPos !== false) {
                // Корректируем позицию начала сущности относительно всей строки
                $relativeAmpersandPos = $maxLength - 9 + $lastAmpersandPos;

                // Проверяем, не разорвана ли сущность
                if ($lastSemicolonPos === false || $lastSemicolonPos < $lastAmpersandPos) {
                    // Сущность разорвана — обрезаем до последнего амперсанда (начала сущности)
                    $currentPart = mb_substr($currentPart, 0, $relativeAmpersandPos, 'UTF-8');
                }
            }

            // Проверяем перенос строки в последних $tailSearchLimit символах только если было разбитие по $maxLength
            if ($isSplitByMaxLength) {
                $tail = mb_substr($currentPart, -$tailSearchLimit, null, 'UTF-8');
                $brEntityPos = mb_strrpos($tail, '&lt;br&gt;', 0, 'UTF-8');
                $newlineEntityPos = mb_strrpos($tail, "\n", 0, 'UTF-8');

                if ($brEntityPos !== false || $newlineEntityPos !== false) {
                    // Если найден перенос, обрезаем строку до его конца
                    $splitPos = ($brEntityPos !== false)
                        ? mb_strlen($currentPart, 'UTF-8') - $tailSearchLimit + $brEntityPos + mb_strlen('&lt;br&gt;', 'UTF-8')
                        : mb_strlen($currentPart, 'UTF-8') - $tailSearchLimit + $newlineEntityPos + mb_strlen("\n", 'UTF-8');
                    $currentPart = mb_substr($currentPart, 0, $splitPos, 'UTF-8');
                } else {
                    // Если перенос строки не найден, ищем пробел в последних $spaceSearchLimit символах
                    $spaceTail = mb_substr($currentPart, -$spaceSearchLimit, null, 'UTF-8');
                    $spacePos = mb_strrpos($spaceTail, ' ', 0, 'UTF-8');
                    if ($spacePos !== false) {
                        $splitPos = mb_strlen($currentPart, 'UTF-8') - $spaceSearchLimit + $spacePos;
                        $currentPart = mb_substr($currentPart, 0, $splitPos, 'UTF-8');
                    }
                }
            }

            // Добавляем текущую часть
            $parts[] = $currentPart;

            // Переходим к следующей части
            $start += mb_strlen($currentPart, 'UTF-8');
        }

        // Декодируем каждую часть обратно в HTML
        return array_map(static fn($part) => html_entity_decode($part, ENT_QUOTES, 'UTF-8'), $parts);
    }

    public function request($method, $params = [], $use_placeholders = true, $dont_use_token = false) {
        $time_start = microtime(true);

        if (isset($params['peer_id']) && is_array($params['peer_id'])) { //возможно везде заменить на peer_ids в методах
            $params['peer_ids'] = join(',', $params['peer_id']);
            unset($params['peer_id']);
        }

        if(!$dont_use_token) {
            $params['access_token'] = $this->token;
        }
        $params['v'] = $this->version;
        if (!is_null($this->group_id) && empty($params['group_id'])) {
            $params['group_id'] = $this->group_id; //а надо ли
        }
        $url = $this->api_url . $method;

        $result = null;

        if (isset($params['message']) && $method === 'messages.send') { //edit нет смысла, просто 2 раза обновится сообщение
            if($use_placeholders) {
                $params['message'] = $this->placeholders($params['message'], $params['peer_id'] ?? null);
            }

            //точно влезет в лимит 4096, потому что 9 символов в html-сущности максимум
            //поэтому конвертацию не делаем
            if(mb_strlen($params['message']) <= 455) {
                $messages = [$params['message']];
            } else {
                $message_with_html_entities = $this->convertMessageToHtmlEntities($params['message']);
                $messages = $this->splitLongMessages($message_with_html_entities);
            }

            foreach ($messages as $message) {
                $params['message'] = $message;
                $result = $this->runRequestWithAttempts($url, $params, $method);
            }
        } else {
            $result = $this->runRequestWithAttempts($url, $params, $method);
        }

        $this->time_checker += (microtime(true) - $time_start);
        return $result;
    }

    public function placeholders($message, $current_vk_id = null) {
        if (!$current_vk_id) {
            $this->initUserID($current_vk_id);
        }

        if (!is_string($message)) {
            return $message;
        }

        $user_ids = [];
        $group_ids = [];
        $tags = ['!fn', '!ln', '!full', 'fn', 'ln', 'full'];

        // Шаблон для поиска всех вхождений вида ~тег|id~
        if (preg_match_all("|~(.*?)~|", $message, $matches)) {
            foreach ($matches[1] as $match) {
                $ex1 = explode('|', $match);
                $tag = $ex1[0];
                $vk_id = $ex1[1] ?? $current_vk_id;

                // Если это один из тегов, то добавляем в соответствующий массив
                if (in_array($tag, $tags)) {
                    if ($vk_id > 0) {
                        $user_ids[] = $vk_id;
                    } elseif ($vk_id < 0) {
                        $group_ids[] = substr($vk_id, 1); // Убираем '-' перед group_id
                    }
                }
            }
        }

        $user_cache = [];
        $group_cache = [];

        if (!empty($user_ids)) {
            $user_infos = $this->request('users.get', ['user_ids' => implode(',', $user_ids)]);
            foreach ($user_infos as $user_info) {
                $user_cache[$user_info['id']] = $user_info;
            }
        }

        if (!empty($group_ids)) {
            $group_infos = $this->request('groups.getById', ['group_ids' => implode(',', $group_ids)])['groups'] ?? [];
            foreach ($group_infos as $group_info) {
                $group_cache[$group_info['id']] = $group_info;
            }
        }

        // Замена тегов в тексте
        return preg_replace_callback(
            "|~(.*?)~|",
            static function ($matches) use ($user_cache, $group_cache, $current_vk_id, $tags) {
                $ex1 = explode('|', $matches[1]);
                $tag = $ex1[0];
                $vk_id = $ex1[1] ?? $current_vk_id;

                if ($vk_id && in_array($tag, $tags)) {
                    if ($vk_id > 0 && isset($user_cache[$vk_id])) {
                        $data = $user_cache[$vk_id];
                        $f = $data['first_name'];
                        $l = $data['last_name'];
                        $replace = ["@id{$vk_id}($f)", "@id{$vk_id}($l)", "@id{$vk_id}($f $l)", $f, $l, "$f $l"];
                        return str_replace($tags, $replace, $tag);
                    }

                    if ($vk_id < 0) {
                        $group_id = substr($vk_id, 1);
                        if (isset($group_cache[$group_id])) {
                            $group_name = $group_cache[$group_id]['name'];
                            return "@club{$group_id}({$group_name})";
                        }
                    }
                }

                return $matches[0];
            },
            $message
        );
    }

    protected function getPayload() {
        if (isset($this->data['object']['payload'])) {
            if (is_string($this->data['object']['payload'])) {
                $payload = json_decode($this->data['object']['payload'], true) ?? $this->data['object']['payload'];
            } else
                $payload = $this->data['object']['payload'];
        } else
            $payload = null;
        return $payload;
    }

    protected function checkTypeEvent() {
        if ($this->data['type'] != 'message_event')
            throw new SimpleVkException(0, "eventAnswerSnackbar можно использовать только при событии message_event");
    }

    protected static function parseUrl($url) {
        if($url) {
            $url = preg_replace("!.*?/!", '', $url);
        }
        return $url === '' ? false : $url;
    }

    protected function setupBackgroundProcessing() {
        error_reporting(E_ALL);
        // ОТКЛЮЧАЕМ вывод ошибок в браузер/stdout.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        // ВКЛЮЧАЕМ логирование ошибок в системный лог или файл.
        // ErrorHandler может это переопределить при использовании
        ini_set('log_errors', '1');
    }

    /**
     * Отправляет клиенту ответ "ok", пытается завершить соединение и продолжить выполнение скрипта в фоне.
     * Этот метод спроектирован для максимальной надежности в различных серверных окружениях.
     *
     * @return bool Возвращает `true`, если удалось использовать нативный механизм (FPM/LiteSpeed) для гарантированного
     *              завершения соединения. Возвращает `false`, если использовался fallback-метод, который не дает
     *              100% гарантии фонового выполнения (например, в cgi-fcgi или mod_php).
     */
    protected function sendOK(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true; // В консоли просто считаем, что задача выполнена.
        }

        // Не были ли заголовки уже отправлены где-то в коде ранее.
        // Если заголовки отправлены, мы уже не можем управлять ими (менять статус, Content-Length и т.д.).
        if (headers_sent()) {
            echo 'ok';
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ob_flush();
            @flush();
            // Включаем поглотитель, чтобы дальнейший мусор не ушел в "сломанное" соединение.
            ob_start(static fn() => '');
            return false;
        }

        @set_time_limit(0);
        // Указываем PHP продолжать выполнение скрипта, даже если пользователь (клиент) разорвал соединение.
        @ignore_user_abort(true);

        // Принудительно отключаем Gzip-сжатие на уровне PHP. Если оно включено, сервер будет
        // буферизировать ВЕСЬ вывод, чтобы сжать его, что полностью сломает `flush()`.
        @ini_set('zlib.output_compression', '0');

        // Дополнительная директива для Apache (mod_php), чтобы отключить сжатие.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // Гарантированно уничтожает ВСЕ буферы вывода, которые могли быть запущены ранее
        // Это гарантирует, что перед отправкой нашего ответа вывод абсолютно чист.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code(200);
        header('Content-Length: 2');

        $isHttp2 = isset($_SERVER['SERVER_PROTOCOL']) && str_starts_with($_SERVER['SERVER_PROTOCOL'], 'HTTP/2');
        if (!$isHttp2) {
            header('Connection: close');
        }

        // Специальные заголовки для управления прокси-серверами
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no');   // Команда для Nginx не буферизировать ответ.
        header('X-Accel-Expires: 0');     // Команда для Nginx не кешировать ответ.

        // Стандартные заголовки для запрета кеширования на всех уровнях (браузер, прокси).
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo 'ok';

        $finished = false;

        // Проверяем наличие нативных функций для "отсоединения". Это лучший и самый надежный способ.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $finished = true;
        } elseif (function_exists('litespeed_finish_request')) {
            \litespeed_finish_request();
            $finished = true;
        }

        // Не дает гарантии, но это лучшее, что можно сделать в таких окружениях, как mod_php.
        if (!$finished) {
            @ob_flush();
            @flush();
        }

        // Включаем поглотитель, чтобы дальнейший мусор не ушел в "сломанное" соединение.
        ob_start(static fn() => '');

        return $finished;
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
