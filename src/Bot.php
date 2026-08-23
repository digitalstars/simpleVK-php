<?php

namespace DigitalStars\SimpleVK;

/**
 * Высокоуровневый конструктор сценариев: кнопки, команды, маски, действия.
 */
class Bot
{
    use FileUploader;

    private SimpleVK $vk;
    private array $config = [];
    private bool $is_text_start = false;
    private bool $is_text_button_triggered = false;
    private bool $is_all_btn_callback = false;
    private bool $case_default = false;
    private int $status = 1;
    private string $color = 'white';
    private array $compile_files = [];
    private array $events = ['message_new', 'message_event'];
    private $before_run = null;
    /** @var callable|null */
    private $anon_time_log_func = null;

    public function __construct($token_or_vk, ?string $version = null, $also_version = null)
    {
        if ($token_or_vk instanceof SimpleVK) {
            $this->vk = $token_or_vk;
        } else {
            if (is_null($version))
                throw new SimpleVkException(0, 'При передачи токена, необходимо передать и версию апи');
            $this->vk = new SimpleVK($token_or_vk, $version, $also_version);
        }
    }

    public static function create($token_or_vk, ?string $version = null, $also_version = null): static
    {
        return new self($token_or_vk, $version, $also_version);
    }

    /**
     * Функция логирования времени выполнения действий: fn(float $ms, string $actionId).
     */
    public function setTimeLoggerFunc(callable $func): static
    {
        $this->anon_time_log_func = $func;
        return $this;
    }

    public function vk(): SimpleVK
    {
        return $this->vk;
    }

    public function setConfirm(string $str): static
    {
        $this->vk->setConfirm($str);
        return $this;
    }

    public function setSecret(string $str): static
    {
        $this->vk->setSecret($str);
        return $this;
    }

    /** @return array<string> Список обрабатываемых типов событий. */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** Добавляет типы событий к обрабатываемым. */
    public function addEvents(array|string $events): static
    {
        if (is_array($events))
            $this->events = array_merge($this->events, $events);
        else
            $this->events[] = $events;
        return $this;
    }

    /** Полностью заменяет список обрабатываемых событий. */
    public function events(array|string $events): static
    {
        if (is_array($events))
            $this->events = $events;
        else
            $this->events = [$events];
        return $this;
    }

    /**
     * Колбэк перед выполнением любого действия: fn($actionId, $peerId, $userId, $resultParse, $idMessage, $isEdit).
     * Возврат truthy отменяет стандартное выполнение.
     */
    public function beforeRun(callable $func): static
    {
        $this->before_run = $func;
        return $this;
    }

    /**
     * Регистрирует кнопку/действие.
     *
     * @param string|int $id Идентификатор кнопки.
     * @param mixed $btn Текст, массив [текст, цвет] или готовый конфиг.
     */
    public function btn(
        string|int $id,
        $btn = null,
        bool $is_callback = false,
        bool $is_text_triggered = false,
    ): MessageBot {
        $id = (string) $id;
        $is_callback = !$this->is_all_btn_callback && $is_callback || $this->is_all_btn_callback && !$is_callback;
        if (isset($btn)) {
            if (is_array($btn)) {
                if (!isset($btn[1]))
                    $btn[1] = $this->color;
                if (count($btn) == 2 and in_array($btn[1], ['white', 'green', 'red', 'blue'], true))
                    $this->config['btn'][$id] = $is_callback
                        ? $this->vk->buttonCallback((string) $btn[0], (string) $btn[1])
                        : $this->vk->buttonText((string) $btn[0], (string) $btn[1]);
                else
                    $this->config['btn'][$id] = $btn;
            } else {
                $this->config['btn'][$id] = $is_callback
                    ? $this->vk->buttonCallback((string) $btn, $this->color)
                    : $this->vk->buttonText((string) $btn, $this->color);
            }
            if (
                ($this->config['btn'][$id][0] ?? null) == 'text'
                and ($is_text_triggered or $this->is_text_button_triggered)
            )
                $this->cmd($id, $this->config['btn'][$id][2]);
        }
        return $this->newAction($id);
    }

    /**
     * Магический алиас btn(): $bot->start('Начать') == $bot->btn('start', 'Начать').
     */
    public function __call(string $name, array $arguments): MessageBot
    {
        return $this->btn($name, $arguments[0] ?? null, $arguments[1] ?? null);
    }

    /** Регистрирует текстовые маски для действия (%n — число, %s — слово). */
    public function cmd(string|int $id, $mask = null, ?bool $is_case = null): MessageBot
    {
        $id = (string) $id;
        $is_case = $is_case ?? $this->case_default;
        if (isset($mask)) {
            $this->config['mask'][$id] = [[], $is_case];
            if (is_array($mask))
                foreach ($mask as $m) {
                    if (!$is_case)
                        $m = mb_strtolower((string) $m);
                    $this->config['mask'][$id][0][] = $m;
                }
            else {
                if (!$is_case)
                    $mask = mb_strtolower((string) $mask);
                $this->config['mask'][$id][0][] = $mask;
            }
        }
        return $this->newAction($id);
    }

    /** Белый список доступа к действию (id пользователя или беседы). */
    public function access(string|int $id, array|int ...$access): static
    {
        // Одиночный аргумент хранится "как есть" (совместимость со старой сигнатурой access($id, $access))
        $this->config['action'][(string) $id]['access'] = count($access) == 1 ? $access[0] : $access;
        return $this;
    }

    /** @return array|null */
    public function getAccess(string|int $id): ?array
    {
        return $this->config['action'][(string) $id]['access'] ?? null;
    }

    /** Чёрный список доступа к действию. */
    public function notAccess(string|int $id, array|int ...$access): static
    {
        $this->config['action'][(string) $id]['not_access'] = count($access) == 1 ? $access[0] : $access;
        return $this;
    }

    /** @return array|null */
    public function getNotAccess(string|int $id): ?array
    {
        return $this->config['action'][(string) $id]['not_access'] ?? null;
    }

    /** Регистрирует действие по регулярному выражению. */
    public function preg_cmd(string|int $id, ?string $mask = null): MessageBot
    {
        if (isset($mask))
            $this->config['preg_mask'][(string) $id] = $mask;
        return $this->newAction((string) $id);
    }

    /** Возвращает внутренний конфиг (для compile/load). */
    public function dump(): array
    {
        return $this->config;
    }

    /** Алиасит действие $id на действие $to_id (общий конфиг по ссылке). */
    public function redirect(string|int $id, string|int $to_id): static
    {
        $this->config['action'][(string) $id] = &$this->config['action'][(string) $to_id];
        return $this;
    }

    private function out_array(mixed $array, string $var, ?int $_livel = null, array $stack = []): string
    {
        $out = $margin = '';
        $nr = "\n";
        $tab = "\t";

        if (is_null($_livel)) {
            $out .= '$' . $var . ' = ';
            if (!empty($array)) {
                $out .= $this->out_array($array, $var, 0);
            }
            $out .= ';';
        } else {
            for ($n = 1; $n <= $_livel; $n++) {
                $margin .= $tab;
            }
            $_livel++;
            if (is_array($array)) {
                $i = 1;
                $count = count($array);
                $out .= '[' . $nr;
                foreach ($array as $key => $row) {
                    $out .= $margin . $tab;
                    if (is_numeric($key)) {
                        $out .= $key . ' => ';
                    } else {
                        $out .= "'" . $key . "' => ";
                    }

                    if (is_array($row)) {
                        $stack[] = $key;
                        $out .= $this->out_array($row, $var, $_livel, $stack);
                        array_pop($stack);
                    } elseif (is_null($row)) {
                        $out .= 'null';
                    } elseif (is_numeric($row)) {
                        $out .= $row;
                    } elseif (is_bool($row)) {
                        $out .= $row ? 'true' : 'false';
                    } elseif (
                        ($stack[0] ?? null) == 'action' and ($key === 'func' or $key === 'func_after')
                        and is_callable($row)
                    ) {
                        $out .= $this->getFunction(end($stack), $key);
                    } else {
                        $out .= "'" . addslashes((string) $row) . "'";
                    }
                    if ($count > $i) {
                        $out .= ',';
                    }
                    $out .= $nr;
                    $i++;
                }
                $out .= $margin . ']';
            } else {
                $out .= "'" . addslashes((string) $array) . "'";
            }
        }
        return $out;
    }

    /**
     * Компилирует конфиг в PHP-файл с инлайн-замыканиями (для быстрого запуска).
     */
    public function compile(string $var = 'compile', string $file = 'cache', bool $is_write = true): string
    {
        $this->compile_files = [];
        $source = $this->out_array($this->config, $var);

        $namespaces_all = [];
        foreach ($this->compile_files as $filename) {
            $tokens = token_get_all((string) file_get_contents($filename));
            $flag = 0;
            $namespaces = '';
            foreach ($tokens as $token) {
                if (is_array($token))
                    if (!$flag)
                        if ($token[0] == T_USE)
                            $flag = 1;
                        else if ($token[0] !== T_WHITESPACE and $token[0] !== T_OPEN_TAG)
                            $flag = 2;
                        else if ($token[0] == T_COMMENT or $token[0] == T_DOC_COMMENT or $token[0] == T_INLINE_HTML)
                            continue;
                $var = is_array($token) ? $token[1] : $token;
                if ($flag == 1) {
                    $namespaces .= $var;
                    if ($var == ';') {
                        if (!in_array($namespaces, $namespaces_all))
                            $namespaces_all[] = $namespaces;
                        $namespaces = '';
                        $flag = 0;
                    }
                } else if ($flag == 2 and $var == ';') {
                    $flag = 0;
                }
            }
        }
        $check_arr_namespace = [];
        foreach ($namespaces_all as $key => $space) {
            $check_space = strtolower(str_replace([' ', "\r", "\n"], '', $space));
            if (in_array($check_space, $check_arr_namespace))
                unset($namespaces_all[$key]);
            else
                $check_arr_namespace[] = $check_space;
        }
        $source = '<?php ' . PHP_EOL . join(PHP_EOL, $namespaces_all) . PHP_EOL . $source;

        file_put_contents(__DIR__ . "/$file.php", $source);
        if ($is_write)
            echo 'Процесс компиляции завершён' . PHP_EOL;
        return $source;
    }

    /** Загружает конфиг (результат dump() другого бота). */
    public function load(array $compile): static
    {
        $this->config = $compile;
        return $this;
    }

    /** Цвет новых кнопок по умолчанию ('white'|'green'|'red'|'blue'). */
    public function setDefaultColor(string $color): static
    {
        if (in_array($color, ['white', 'green', 'red', 'blue'], true))
            $this->color = $color;
        else
            throw new SimpleVkException(0, 'Неверное название цвета');
        return $this;
    }

    /** Регистрозависимые маски по умолчанию. */
    public function setCaseDefault(bool $case = true): static
    {
        $this->case_default = $case;
        return $this;
    }

    /** Автоматически создавать текстовый триггер для каждой текстовой кнопки. */
    public function isTextBtnTriggered(bool $status = true): static
    {
        $this->is_text_button_triggered = $status;
        return $this;
    }

    public function msg(?string $text = null): MessageBot
    {
        $cfg = null;
        return MessageBot::create($this->vk, $cfg, $this, $this->config['btn'])->text($text);
    }

    /** Открывает существующую кнопку для редактирования. */
    public function editBtn(string|int $id, bool $is_save = false): Button
    {
        $id = (string) $id;
        if (!isset($this->config['btn'][$id]))
            throw new SimpleVkException(0, "Кнопка с id '$id' не найдена");
        return Button::create($this->config['btn'][$id], $is_save, $id);
    }

    private function newAction(string $id): MessageBot
    {
        if (!isset($this->config['action'][$id]))
            $this->config['action'][$id] = [];
        return new MessageBot($this->vk, $this->config['action'][$id], $this, $this->config['btn'], $id);
    }

    /** Сбрасывает статус обработки (используется внутри замыканий func). */
    public function break(): void
    {
        $this->status = 1;
    }

    /** 0 — действие выполнилось, 1 — цепочка прервана/не выполнялась. */
    public function getStatus(): int
    {
        return $this->status;
    }

    private function runAction(
        string|int $id,
        int|string $user_id,
        string|int $action_id,
        $result_parse = null,
        ?array $id_message = null,
        bool $is_edit = false,
    ) {
        $id = (string) $id;
        $action_id = (string) $action_id;
        if (is_callable($this->before_run))
            if (call_user_func($this->before_run, $action_id, $id, $user_id, $result_parse, $id_message, $is_edit))
                return null;
        if (isset($this->config['action'][$action_id]['access'])) {
            $flag = false;
            foreach ($this->config['action'][$action_id]['access'] as $access)
                if (
                    is_array($access) and $access[0] == $id and in_array($user_id, $access)
                    or is_numeric($access) and ($id == $access or $user_id == $access)
                ) {
                    $flag = true;
                    break;
                }
            if (!$flag)
                return null;
        }
        if (isset($this->config['action'][$action_id]['not_access']))
            foreach ($this->config['action'][$action_id]['not_access'] as $access)
                if (
                    is_array($access) and $access[0] == $id and in_array($user_id, $access)
                    or is_numeric($access) and ($id == $access or $user_id == $access)
                )
                    return null;
        $this->status = 0;

        $this->vk->time_checker = microtime(true);

        $is_edit = $is_edit || ($this->config['action'][$action_id]['is_edit'] ?? false);
        if ($is_edit) {
            if ($id_message['type'])
                $result = MessageBot::create(
                    $this->vk,
                    $this->config['action'][$action_id],
                    $this,
                    $this->config['btn'],
                    $action_id,
                )->sendEdit($id, $id_message['id'], null, $result_parse);
            else
                $result = MessageBot::create(
                    $this->vk,
                    $this->config['action'][$action_id],
                    $this,
                    $this->config['btn'],
                    $action_id,
                )->sendEdit($id, null, $id_message['id'], $result_parse);
        } else {
            $result = MessageBot::create(
                $this->vk,
                $this->config['action'][$action_id],
                $this,
                $this->config['btn'],
                $action_id,
            )->send($id, null, $result_parse);
        }
        $this->status = 0;

        $time_exec = round((microtime(true) - $this->vk->time_checker) * 1000, 2);
        $func = $this->anon_time_log_func;
        if ($func !== null) {
            $func($time_exec, $action_id);
        }

        return $result;
    }

    /** Извлекает исходник замыкания func/func_after из файла (для compile). */
    private function getFunction(string $id, string $type): string
    {
        $func_info = new \ReflectionFunction($this->config['action'][$id][$type]);
        $filename = $func_info->getFileName();
        $start_line = $func_info->getStartLine() - 1;
        $end_line = $func_info->getEndLine();
        $length = $end_line - $start_line;

        if (!in_array($filename, $this->compile_files))
            $this->compile_files[] = $filename;

        $source = file($filename);
        $body = implode('', array_slice($source, $start_line, $length));
        $tokens = token_get_all('<?php ' . $body);
        $flag = 0;
        $brackets = 0;
        $result = '';
        $type = $type == 'func' ? 'func' : 'afterFunc';
        $cache = '';
        foreach ($tokens as $token) {
            if (is_string($token))
                $var = $token;
            else {
                if ($token[0] == T_DOC_COMMENT or $token[0] == T_COMMENT)
                    continue;
                else if ($token[0] != T_CONSTANT_ENCAPSED_STRING)
                    $var = str_replace(["\t", "\r", "\n"], '', $token[1]);
                else
                    $var = $token[1];
            }
            if ($flag > 0) {
                $result .= $var;
                if ($var == '{') {
                    ++$brackets;
                    $flag = 2;
                } else if ($var == '}')
                    --$brackets;
                if ($brackets == 0 and $flag == 2)
                    $flag = 0;
            } else if ($var == $type) {
                $flag = -1;
                $cache = $result;
                $result = '';
            } else if ($var == 'function' and ($result == '' or $flag == -1)) {
                $flag = 1;
                $cache = $result;
                $result = $var;
            }
        }
        return $brackets == 0 ? $result : $cache;
    }

    /**
     * Запускает действие как редактирование сообщения.
     */
    public function editRun(string|int $send, string|int $id, int|string $id_message)
    {
        $send = (string) $send;
        if (!is_numeric($id_message))
            throw new SimpleVkException(0, 'Не пришёл id сообщения');
        if (empty($this->config['action'][$send]))
            throw new SimpleVkException(0, "Событие $send не найдено");
        $this->vk->initUserID($user_id)->initPayload($payload);
        return $this->runAction($id, $user_id, $send, $payload, ['id' => $id_message, 'type' => true], true);
    }

    /**
     * Главный обработчик события: находит и выполняет подходящее действие.
     *
     * @param string|int|null $send Принудительный ID действия.
     * @param string|int|null $id Переопределение peer_id.
     */
    public function run($send = null, $id = null)
    {
        $data = $this->vk->initVars($id_now, $user_id, $type, $message, $payload);
        $id = $id ?? $id_now;
        if (isset($send))
            if (isset($this->config['action'][(string) $send]))
                return $this->runAction($id, $user_id, $send, $payload);
            else
                throw new SimpleVkException(0, "События с ID '$send' не существует");
        if (!in_array($type, $this->events, true))
            return null;
        $message_id = ['id' => $data['object']['conversation_message_id'] ?? null, 'type' => false];
        if (isset($payload['name']) and isset($this->config['action'][$payload['name']]))
            return $this->runAction($id, $user_id, $payload['name'], $payload, $message_id);
        if (isset($payload['command']) and $payload['command'] == 'start' or $this->is_text_start)
            return $this->runAction($id, $user_id, 'first', $payload, $message_id);
        if (!empty($message)) {
            if (isset($this->config['mask'])) {
                $arr_msg = explode(' ', (string) $message);
                foreach ($this->config['mask'] as $action => $masks)
                    foreach ($masks[0] as $mask) {
                        $mask_words = explode(' ', (string) $mask);
                        if (count($mask_words) != count($arr_msg))
                            continue;
                        $flag = true;
                        $result_parse = [];
                        foreach ($mask_words as $index => $word) {
                            if ($word == '%n') {
                                $number_temp = str_replace(',', '.', $arr_msg[$index]);
                                if (is_numeric($number_temp))
                                    $result_parse[] = (float) $number_temp;
                                else {
                                    $flag = false;
                                    break;
                                }
                            } else if ($word == '%s' and is_string($arr_msg[$index])) {
                                $result_parse[] = $arr_msg[$index];
                            } else if (
                                (!$masks[1] or $word != $arr_msg[$index])
                                and ($masks[1] or $word != mb_strtolower($arr_msg[$index]))
                            ) {
                                $flag = false;
                                break;
                            }
                        }
                        if ($flag)
                            return $this->runAction($id, $user_id, $action, $result_parse, $message_id);
                    }
            }
            if (isset($this->config['preg_mask']))
                foreach ($this->config['preg_mask'] as $action => $preg_mask)
                    if (preg_match($preg_mask, (string) $message, $result_parse))
                        return $this->runAction($id, $user_id, $action, $result_parse, $message_id);
        }
        if (isset($this->config['action']['other']))
            return $this->runAction($id, $user_id, 'other', null, $message_id);
        return null;
    }
}

/**
 * Message с поддержкой кнопок из Bot::btn(), цепочек действий и eventAnswer.
 */
class MessageBot extends Message
{
    protected ?array $buttons;
    /** @var Bot|null */
    protected $bot = null;
    protected ?string $id_action = null;

    public function __construct($vk = null, &$cfg = null, ?Bot $bot = null, &$buttons = null, ?string $id_action = null)
    {
        $this->buttons = &$buttons;
        $this->bot = $bot;
        $this->id_action = $id_action;
        parent::__construct($vk, $cfg);
    }

    public static function create(
        $vk = null,
        &$cfg = null,
        ?Bot $bot = null,
        &$buttons = null,
        ?string $id_action = null,
    ): static {
        return new self($vk, $cfg, $bot, $buttons, $id_action);
    }

    public function load($cfg = []): static
    {
        // ВАЖНО: проверка self раньше Message (MessageBot наследует Message,
        // иначе ветка self недостижима и кнопки не переезжают)
        if ($cfg instanceof self) {
            $this->vk = $cfg->vk;
            $this->config = $cfg->config;
            $this->buttons = &$cfg->buttons;
        } else if ($cfg instanceof Message) {
            $this->vk = $cfg->vk;
            $this->config = $cfg->config;
        } else {
            $this->config = $cfg;
        }
        return $this;
    }

    /**
     * Клавиатура с поддержкой имён кнопок из Bot::btn().
     */
    public function kbd(array|string|object $kbd = [], int|bool $inline = false, bool $one_time = false): static
    {
        if (is_string($kbd) || isset($kbd[0]) && is_string($kbd[0])) {
            $kbd = [[$kbd]];
        }
        $this->config['kbd'] = ['kbd' => $kbd, 'inline' => (bool) $inline, 'one_time' => $one_time];
        return $this;
    }

    public function eventAnswerSnackbar(string $text): static
    {
        $this->config['event'] = [
            'type' => 0,
            'text' => $text,
        ];
        return $this;
    }

    public function eventAnswerOpenLink(string $url): static
    {
        $this->config['event'] = [
            'type' => 1,
            'url' => $url,
        ];
        return $this;
    }

    public function eventAnswerOpenApp(
        int|string $app_id,
        int|string|null $owner_id = null,
        ?string $hash = null,
    ): static {
        $this->config['event'] = [
            'type' => 2,
            'app_id' => $app_id,
            'owner_id' => $owner_id,
            'hash' => $hash,
        ];
        return $this;
    }

    /** После отправки текущего сообщения запустить действие $id. */
    public function a_run(int|string $id): static
    {
        $this->config['func_after_chain'][] = ['f' => 'run', 'args' => $id];
        return $this;
    }

    /** Перед отправкой текущего сообщения запустить действие $id. */
    public function b_run(int|string $id): static
    {
        $this->config['func_before_chain'][] = ['f' => 'run', 'args' => $id];
        return $this;
    }

    /** Создаёт новое под-действие, запускаемое после текущего. */
    public function run(): MessageBot
    {
        $id = $this->generateNewAction();
        $this->config['func_after_chain'][] = ['f' => 'run', 'args' => $id];
        return $this->bot->cmd($id);
    }

    /**
     * Превращает отправку в messages.edit. При $is_save переносит контент в новое под-действие.
     */
    public function edit(
        bool $is_save = true,
        array $save_params = ['text', 'img', 'doc', 'attachments', 'params', 'voice', 'kbd'],
    ): static|MessageBot {
        if (!empty(array_intersect(array_keys($this->config), [
            'text',
            'img',
            'doc',
            'attachments',
            'params',
            'voice',
            'kbd',
            'func',
        ]))) {
            $id = $this->generateNewAction();
            $this->config['func_after_chain'][] = ['f' => 'edit', 'args' => $id];
            if ($is_save) {
                $new_msg_config = [];
                foreach ($this->config as $key => $val)
                    if (in_array($key, $save_params))
                        $new_msg_config[$key] = $val;
                return $this->bot->cmd($id)->load($new_msg_config);
            } else
                return $this->bot->cmd($id);
        } else {
            $this->config['is_edit'] = true;
            return $this;
        }
    }

    private function generateNewAction(): string
    {
        $parts = explode('$', (string) $this->id_action);
        if (count($parts) > 2 or isset($parts[1]) and !is_numeric($parts[1]))
            throw new SimpleVkException(0, "Нельзя использовать '$' в id действий");
        $parts[1] = isset($parts[1]) ? $parts[1] + 1 : 1;
        return join('$', $parts);
    }

    /** Ограничивает доступ к действию (id пользователей/бесед). */
    public function access(array|int ...$ids): static
    {
        // Передаём список аргументов одним массивом — как раньше делал func_get_args()
        $this->bot->access($this->id_action, $ids);
        return $this;
    }

    public function getAccess(): ?array
    {
        return $this->bot->getAccess($this->id_action);
    }

    public function notAccess(array|int ...$ids): static
    {
        $this->bot->notAccess($this->id_action, $ids);
        return $this;
    }

    public function getNotAccess(): ?array
    {
        return $this->bot->getNotAccess($this->id_action);
    }

    public function redirect(string|int $id): Bot
    {
        return $this->bot->redirect($this->id_action, $id);
    }

    /**
     * Заменяет имена кнопок на их конфиги с payload {'name': id}.
     */
    protected function parseKbd(array $kbd): array
    {
        $kbd_result = $kbd;
        foreach ($kbd as $row_index => $row)
            foreach ($row as $col_index => $col) {
                if (!is_string($col)) {
                    $kbd_result[$row_index][$col_index] = $col;
                    continue;
                }
                if (!isset($this->buttons[$col]))
                    throw new SimpleVkException(
                        0,
                        'Кнопки с id '
                        . $col
                        . ' не найдена. Возможно вы используете для отправки сообщения не тот экземпляр класса, в котором была создана эта кнопка.',
                    );
                $btn = $this->buttons[$col];
                $payload = ['name' => $col];
                if (is_array($btn[1]))
                    $btn[1] = array_merge($btn[1], $payload);
                else
                    $btn[1] = $payload;
                $kbd_result[$row_index][$col_index] = $btn;
            }
        return $kbd_result;
    }
}

/**
 * Модификатор уже созданной кнопки (editBtn).
 */
class Button
{
    /** @var mixed Конфиг кнопки (по ссылке при $is_save). */
    private $config;
    private string $id;

    public function __construct(&$config, bool $is_save, string $id)
    {
        if ($is_save)
            $this->config = &$config;
        else
            $this->config = $config;
        $this->id = $id;
    }

    public static function create(&$config, bool $is_save, string $id): static
    {
        return new self($config, $is_save, $id);
    }

    /** Перезаписывает payload кнопки (ключ name зарезервирован). */
    public function payload(array $payload): static
    {
        if (in_array('name', array_keys($payload)))
            throw new SimpleVkException(0, 'Нельзя использовать name в payload');
        $this->config[1] = array_merge($payload, ['name' => $this->id]);
        return $this;
    }

    /** Дополняет payload кнопки. */
    public function addPayload(array $payload): static
    {
        if (in_array('name', array_keys($payload)))
            throw new SimpleVkException(0, 'Нельзя использовать name в payload');
        $this->config[1] = array_merge($this->config[1] ?? [], $payload, ['name' => $this->id]);
        return $this;
    }

    public function getPayload(): array
    {
        return $this->config[1];
    }

    /** Меняет текст кнопки (или label ссылки). */
    public function text(string $text): static
    {
        if (!in_array($this->config[0], ['text', 'callback', 'open_link', 'open_app']))
            throw new SimpleVkException(0, 'У этого типа кнопок нельзя задать текст');
        if ($this->config[0] == 'open_link')
            $this->config[3] = $text;
        else
            $this->config[2] = $text;
        return $this;
    }

    public function getText(): string
    {
        if (!in_array($this->config[0], ['text', 'callback', 'open_link', 'open_app']))
            throw new SimpleVkException(0, 'У этого типа кнопок нельзя задать текст');
        return $this->config[0] == 'open_link' ? $this->config[3] : $this->config[2];
    }

    /** Меняет адрес ссылки (только для open_link). */
    public function link(string $link): static
    {
        if ($this->config[0] != 'open_link')
            throw new SimpleVkException(0, 'У этого типа кнопок нельзя задать адрес ссылке');
        $this->config[2] = $link;
        return $this;
    }

    public function getLink(): string
    {
        if ($this->config[0] != 'open_link')
            throw new SimpleVkException(0, 'У этого типа кнопок нельзя задать адрес ссылке');
        return $this->config[2];
    }

    public function dump(): array
    {
        return $this->config;
    }

    public function type(): string
    {
        return $this->config[0];
    }
}
