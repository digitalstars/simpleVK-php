<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Internal\UniqueEventHandler;
use DigitalStars\SimpleVK\Utils\EnvironmentDetector;

/**
 * Long Poll клиент (Bots Long Poll API и User Long Poll API).
 */
class LongPoll extends SimpleVK
{
    use ErrorHandler;

    private string $key;
    private string $server;
    private int|string|null $ts = null;
    private string $auth_type;
    private bool $is_multi_thread = false;
    /** @var array<int, string> Биты флагов события user-longpoll. */
    private array $event_flags = [];
    private static bool $longpoll_in_web = false;
    public static int $use_user_long_poll = 0;

    public function __construct($token, $version, $also_version = null, $data = null)
    {
        if (EnvironmentDetector::isWeb() && self::$longpoll_in_web == false)
            die(
                'Запуск longpoll возможен только в cli. Используйте LongPoll::enableInWeb() чтобы убрать это ограничение.',
            );
        $this->multiThread();
        $this->processAuth($token, $version, $also_version);
        $data = $this->userInfo();
        if ($data != false || self::$use_user_long_poll) {
            $this->auth_type = 'user';
        } else {
            $this->auth_type = 'group';
            $this->group_id = $this->request('groups.getById')['groups'][0]['id'];
        }
        $this->getLongPollServer();
    }

    public static function create($token, $version, $also_version = null, $data = null): static
    {
        return new self($token, $version, $also_version, $data);
    }

    /** Разрешает запуск longpoll из веб-окружения. */
    public static function enableInWeb(bool $bool = true): void
    {
        self::$longpoll_in_web = $bool;
    }

    /**
     * Проверить наличие модулей многопоточности и включить форки, если есть.
     */
    private function multiThread(): void
    {
        $this->is_multi_thread = extension_loaded('posix') && extension_loaded('pcntl');
    }

    /**
     * Основной цикл обработки событий.
     *
     * @param callable $anon fn(array $event): void
     */
    public function listen(callable $anon): void
    {
        while ($data = $this->processingData()) {
            foreach ($data['updates'] as $event) {
                $is_dublicated = UniqueEventHandler::addEventToCache($event);
                if ($is_dublicated) {
                    continue;
                }

                if ($this->is_multi_thread) {
                    // Подчищаем завершившиеся дочерние процессы перед новым форком
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
        }
    }

    /** Завершает дочерний процесс после обработки события. */
    private function __exit(): never
    {
        posix_kill(posix_getpid(), SIGTERM);
        exit(0);
    }

    private function getLongPollServer(): void
    {
        if ($this->auth_type == 'user')
            $data = $this->request('messages.getLongPollServer', ['need_pts' => 1, 'lp_version' => 10]);
        else
            $data = $this->request('groups.getLongPollServer', ['group_id' => $this->group_id]);
        list($this->key, $this->server, $this->ts) = [$data['key'], $data['server'], $data['ts']];
    }

    /**
     * Забирает очередную пачку событий, обрабатывая failed-ответы сервера.
     *
     * @return array|null
     */
    private function processingData(): ?array
    {
        while ($data = $this->getData()) {
            if (isset($data['failed'])) {
                switch ($data['failed']) {
                    case 1:
                        $this->ts = $data['ts'];
                        break;
                    case 2:
                    case 3:
                        $this->getLongPollServer();
                        break;
                }
                continue;
            }

            $this->ts = $data['ts'];
            return $data;
        }
        return null;
    }

    /**
     * @return array|null Раскодированный ответ longpoll-сервера.
     * @throws SimpleVkException После 5 пустых ответов подряд.
     */
    private function getData(): ?array
    {
        $default_params = ['act' => 'a_check', 'key' => $this->key, 'ts' => $this->ts, 'wait' => 25];
        try {
            if ($this->auth_type == 'user') {
                $params = ['mode' => 2 | 8 | 32 | 64 | 128, 'version' => 10];
                $data = $this->request_core_lp('https://' . $this->server . '?', $default_params + $params);
            } else {
                $data = $this->request_core_lp($this->server . '?', $default_params);
            }
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            throw new SimpleVkException((int) $e->getCode(), $e->getMessage());
        }
    }

    private function request_core_lp(string $url, array $params = [], int $iteration = 1): ?array
    {
        $ch = $this->curlInit();
        curl_setopt($ch, CURLOPT_URL, $url . http_build_query($params));
        $raw = curl_exec($ch);
        $result = is_string($raw) ? json_decode($raw, true) : null;
        unset($ch);

        if (!isset($result)) {
            if ($iteration <= 5) {
                SimpleVkException::logCustomError('Запрос к вк вернул пустоту. Повторная отправка, попытка №'
                . $iteration);
                return $this->request_core_lp($url, $params, ++$iteration);
            }
            $error_message =
                "Запрос к вк вернул пустоту. Завершение 5 попыток отправки\n
                              Метод:$url\nПараметры:\n"
                . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            SimpleVkException::logCustomError($error_message);
            throw new \Exception($error_message, 77777);
        }
        return $result;
    }

    /**
     * Нормализует событие user-longpoll в структуру, совместимую с Callback API,
     * и вызывает обработчик.
     *
     * @param callable $anon fn(array $event): void
     */
    private function userLongPoll(callable $anon): void
    {
        $data = $this->data;
        $this->data = [];
        if (isset($data[2]))
            $this->initFlags($data[2]);
        switch ((int) ($data[0] ?? 0)) {
            case 2:
                { // Установка флагов сообщения
                    $this->data['type'] = 'set_message_flags';
                    $this->parseMessageStruct($data);
                    $this->data['flags']['important'] = $this->flag(3);
                    $this->data['flags']['spam'] = $this->flag(6);
                    $this->data['flags']['deleted'] = $this->flag(7);
                    $this->data['flags']['deleted_all'] = (int) ($this->flag(7) && $this->flag(17));
                    $this->data['flags']['audio_listened'] = $this->flag(12);
                    break;
                }
            case 3:
                { // Снятие флагов сообщения
                    $this->data['type'] = 'unset_message_flags';
                    $this->parseMessageStruct($data);
                    $this->data['flags']['important'] = $this->flag(3);
                    $this->data['flags']['cancel_spam'] = (int) ($this->flag(6) && $this->flag(15));
                    $this->data['flags']['deleted'] = $this->flag(7);
                    break;
                }
            case 4:
                { // входящее/исходящее сообщение
                    $this->data['type'] = $this->flag(1) ? 'message_reply' : 'message_new';
                    $this->parseMessageStruct($data);
                    $this->parseMessageFlags();
                    break;
                }
            case 5:
                { // редактирование сообщения
                    $this->data['type'] = 'message_edit';
                    $this->parseMessageStruct($data);
                    $this->parseMessageFlags();
                    break;
                }
            case 18:
                { // добавление сниппета к сообщению
                    $this->data['type'] = 'vk_add_snippet';
                    $this->parseMessageStruct($data);
                    $this->parseMessageFlags();
                    break;
                }
        }
        $this->data_backup = $this->data;
        $anon($data);
    }

    private function parseMessageFlags(): void
    {
        $this->data['flags']['unread'] = $this->flag(0);
        $this->data['flags']['chat'] = $this->flag(4);
        $this->data['flags']['friends'] = $this->flag(5);
        $this->data['flags']['chat2'] = $this->flag(13);
        $this->data['flags']['hidden'] = $this->flag(16);
        $this->data['flags']['chat_in'] = $this->flag(19);
        $this->data['flags']['silent'] = $this->flag(20);
        $this->data['flags']['reply_msg'] = $this->flag(21);
    }

    /**
     * Раскладывает массив структуры user-longpoll в объектную форму события.
     */
    private function parseMessageStruct(array $data): void
    {
        $this->data['object']['id'] = $data[1] ?? null;
        $this->data['object']['peer_id'] = $data[3] ?? null;
        if (isset($data[4])) {
            $this->data['object']['date'] = $data[4] ?? null;
            $this->data['object']['text'] = $data[5] ?? null;
            $this->data['object']['from_id'] = $data[6]['from'] ?? $this->data['object']['peer_id'];

            if (isset($data[6]['source_act'])) {
                $this->data['object']['source'] = $data[6];
            } else {
                $extra = is_array($data[6] ?? null) ? $data[6] : [];
                $this->data['object']['temp']['emoji'] = $extra['emoji'] ?? null;
                $this->data['object']['temp']['marked_users'] = $extra['marked_users'][0][1] ?? null;
                $this->data['object']['temp']['keyboard'] = $extra['keyboard'] ?? null;
                $this->data['object']['temp']['expire_ttl'] = $extra['expire_ttl'] ?? null;
                $this->data['object']['temp']['ttl'] = $extra['ttl'] ?? null;
                $this->data['object']['temp']['is_expired'] = $extra['is_expired'] ?? null;
            }

            $this->data['object']['attachments'] = $data[7] ?? null;
            $this->data['object']['random_id'] = !empty($data[8]) ? $data[8] : null;
            $this->data['object']['conversation_message_id'] = $data[9] ?? null;
            $this->data['object']['edit_time'] = $data[10] ?? null; // 0 (не редактировалось) или timestamp редактирования
        }
        $this->data_backup = $this->data;
    }

    /**
     * Разбирает битовую маску флагов в массив '0'/'1'.
     */
    private function initFlags(int|array $data): void
    {
        $mask = is_array($data) ? $data[0] : $data;
        // Индекс элемента == номер флага: event_flags[$n] === '1', если флаг n установлен
        $this->event_flags = str_split(strrev(decbin((int) $mask)));
    }

    /** Возвращает '1'/'0' (исторически строка) состояния флага. */
    private function flag(int $f): string|int
    {
        return $this->event_flags[$f] ?? 0;
    }
}
