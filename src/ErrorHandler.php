<?php

namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

use Closure;
use DigitalStars\SimpleVK\Utils\EnvironmentDetector;
use ErrorException;
use Throwable, Exception;

trait ErrorHandler
{

    private Closure|array|null $user_error_handler_or_ids = null;

    private array $paths_to_filter = [];

    private bool $short_trace = false;

    private bool $send_error_in_vk = true;

    private array $snippet_cache = [];

    private bool $isAlreadyExiting = false;

    private const NO_TRACE_ERROR_TYPES = [
        E_WARNING,   // Системные предупреждения
        E_NOTICE,    // Системные уведомления
        E_USER_WARNING,   // Пользовательские предупреждения
        E_USER_NOTICE,    // Пользовательские уведомления
        E_USER_ERROR,     // Пользовательские ошибки
        E_DEPRECATED,     // Устаревшие функции
        E_USER_DEPRECATED // Пользовательские устаревшие функции
    ];

    private const VENDOR_PATH_PATTERN = '#/(vendor|simplevk[^/]*/src)(/.*)#i';

    /**
     * Устанавливает обработчик ошибок и исключений, перенаправляя их для логирования и вывода.
     * @param int|array<int>|callable $ids VK ID пользователя, массив ID или функция-обработчик.
     * @return SimpleVK|ErrorHandler|LongPoll Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function setUserLogError(callable|array|int $ids): self
    {
        $this->user_error_handler_or_ids = is_numeric($ids) ? [$ids] : $ids;

        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', 1);
        //при включении log_errors по умолчанию вывод идет в stderr, что приводит к дубрированию ошибок
        ini_set('log_errors', 0);
        // Перенаправляет ошибки в файл, а не в stderr
        // ini_set('error_log', '/path/to/php-error.log');
        ini_set('display_startup_errors', 1);

        set_error_handler([$this, 'userErrorHandler']); //Для пользовательских ошибок и всех нефатальных
        set_exception_handler([$this, 'exceptionHandler']); //Для необработанных исключений
        //Для обнаружения фатальных ошибок, из-за которых не успевают сработать обычные обработчики
        register_shutdown_function(fn() => $this->checkForFatalError());
        return $this;
    }

    /**
     * Устанавливает пути к файлам, которые необходимо убрать из трейса
     * @param string|array $pathes Путь или массив путей
     * @return void
     */
    public function setTracePathFilter(string|array $pathes): void
    {
        $pathes = is_string($pathes) ? [$pathes] : $pathes;
        $this->paths_to_filter = array_map(static fn($path) => str_replace('\\', '/', $path), $pathes);
    }

    /**
     * Оставляет в трейсе только пользовательские файлы, без файлов библиотеки
     * @param bool $enable - вкл/выкл отображение короткого трейса
     * @return SimpleVK|ErrorHandler|LongPoll Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function shortTrace(bool $enable = true): self
    {
        $this->short_trace = $enable;
        return $this;
    }

    /**
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     */
    public function exceptionHandler(
        Throwable $exception,
        int $set_type = E_ERROR,
    ): void {
        $message = $this->normalizeMessage($exception->getMessage());
        $message = $this->filterPaths($message);
        $file = $this->normalizeMessage($exception->getFile());
        $line = (int) $this->normalizeMessage($exception->getLine()); // Приведение к int для надежности
        $code = $exception->getCode();

        $this->userErrorHandler(
            $set_type,
            $message,
            $file,
            $line,
            $code,
            $exception,
        );
    }

    /**
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     */
    public function userErrorHandler(
        int $type,
        string $message,
        string $file,
        int $line,
        ?int $code = null,
        ?Throwable $exception = null,
    ): bool {
        // если ошибка не подавлена оператором @
        if (!(error_reporting() & $type)) {
            return true; // Не обрабатываем подавленные ошибки
        }

        $is_artificial_trace = false;

        if ($exception) {
            $trace_data = $exception->getTrace();

            if (empty($trace_data)) {
                $trace_data = [['file' => $file, 'line' => $line]];
                $is_artificial_trace = true;
            }
        } else {
            $exception = new ErrorException($message, 0, $type, $file, $line);
            $trace_data = $exception->getTrace();
            $is_artificial_trace = true;
        }

        // --- 2. Определение уровня и типа ошибки ---
        [$error_level, $error_type] = $this->defaultErrorLevelMap()[$type] ?? ['NOTICE', 'UNKNOWN'];

        $trace_model = $this->prepareTraceModel($trace_data, $file, $line, $is_artificial_trace);

        // 2. РЕНДЕРИНГ ТРЕЙСОВ
        $console_trace = $this->renderTrace($trace_model, with_colors: true, shorten_paths: false);
        $vk_trace = $this->renderTrace($trace_model, with_colors: false, shorten_paths: true);
        $plain_trace = $this->renderTrace($trace_model, with_colors: false, shorten_paths: false);

        // 3. ФОРМИРОВАНИЕ ЗАГОЛОВКОВ
        $console_header = $this->formatErrorLevel($error_level) . $message;
        $plain_header   = strip_tags(preg_replace('/\033\[[0-9;]*m/', '', $console_header));

        // 4. СБОРКА ФИНАЛЬНЫХ СООБЩЕНИЙ
        $console_message = "$console_header\n\n$console_trace";
        $plain_message   = "$plain_header\n\n$plain_trace";
        $vk_message      = "$plain_header\n\n$vk_trace";

        // --- 4. Логирование ---
        if ($this->shouldLogException($exception, $code)) {
            SimpleVkException::logCustomError($vk_message);
        }

        // --- 5. Вывод в консоль/веб/лог ---
        $this->displayError($console_message, $plain_message);

        // --- 5. Отправка ошибки в чат ВК ---
        $this->dispatchErrorMessage($error_type, $vk_message, $code, $exception);

        // Завершение работы при критических ошибках
        if (in_array($error_level, ['ERROR', 'CRITICAL'])) {
            $this->isAlreadyExiting = true; //чтобы не сработала register_shutdown_function()
            exit(1);
        }

        return true; // Подавляем стандартный обработчик PHP
    }

    private function shouldLogException(?Throwable $exception, ?int $code): bool
    {
        return !($exception instanceof SimpleVkException && in_array($code, ERROR_CODES_FOR_MANY_TRY, true));
    }

    private function displayError(string $coloredMessage, string $clearMessage): void
    {
        match (EnvironmentDetector::getEnvironment()) {
            EnvironmentDetector::ENV_WEB => print "<pre>" . htmlspecialchars($clearMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>",
            EnvironmentDetector::ENV_CLI_INTERACTIVE => print $coloredMessage,
            EnvironmentDetector::ENV_CLI_NON_INTERACTIVE => print $clearMessage,
        };
    }

    private function checkForFatalError(): void
    {
        if ($this->isAlreadyExiting) {
            return;
        }
        if ($error = error_get_last()) {
            $type = $error['type'];
            if ($type & DEFAULT_ERROR_LOG) {
                $exception = new ErrorException(
                    $error['message'],
                    0, // code
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                $this->exceptionHandler($exception);
            }
        }
    }

    private function defaultErrorLevelMap(): array
    {
        return [
            E_ERROR => ['CRITICAL', 'E_ERROR'],
            E_WARNING => ['WARNING', 'E_WARNING'],
            E_PARSE => ['ERROR', 'E_PARSE'],
            E_NOTICE => ['NOTICE', 'E_NOTICE'],
            E_CORE_ERROR => ['CRITICAL', 'E_CORE_ERROR'],
            E_CORE_WARNING => ['WARNING', 'E_CORE_WARNING'],
            E_COMPILE_ERROR => ['CRITICAL', 'E_COMPILE_ERROR'],
            E_COMPILE_WARNING => ['WARNING', 'E_COMPILE_WARNING'],
            E_USER_ERROR => ['ERROR', 'E_USER_ERROR'],
            E_USER_WARNING => ['WARNING', 'E_USER_WARNING'],
            E_USER_NOTICE => ['NOTICE', 'E_USER_NOTICE'],
            E_RECOVERABLE_ERROR => ['ERROR', 'E_RECOVERABLE_ERROR'],
            E_DEPRECATED => ['NOTICE', 'E_DEPRECATED'],
            E_USER_DEPRECATED => ['NOTICE', 'E_USER_DEPRECATED'],
        ];
    }

    private function formatErrorLevel(string $level): string
    {
        return match ($level) {
            'ERROR', 'CRITICAL' => $this->coloredLog('‼Fatal Error: ', 'RED'),
            'WARNING' => $this->coloredLog('⚠️Warning: ', 'YELLOW'),
            'NOTICE' => $this->coloredLog('⚠️Notice: ', 'BLUE'),
            default => $this->coloredLog('‼Unknown Error: ', 'RED'),
        };
    }

    private function coloredLog(string $text, string $color)
    {
        $color_codes = [
            'RED' => "\033[31m",
            'GREEN' => "\033[32m",
            'YELLOW' => "\033[33m",
            'BLUE' => "\033[34m",
            'WHITE' => "\033[37m",
        ];
        $color_reset = "\033[0m";
        $color_code = $color_codes[$color];
        return "{$color_code}{$text}{$color_reset}";
    }

    private function dispatchErrorMessage(
        string $type,
        string $message,
        ?int $code = null,
        ?Throwable $exception = null
    ): void {
        if (is_callable($this->user_error_handler_or_ids)) {
            call_user_func($this->user_error_handler_or_ids, $type, $message, $code, $exception);
        } else {
            $peer_ids = implode(',', $this->user_error_handler_or_ids);
            if ($this->send_error_in_vk) {
                try {
                    //Ошибки не вызываются при недоставке юзеру, потому что у peer_ids другой формат ответа
                    $this->request('messages.send', [ //отправка ошибки в ВК
                        'peer_ids' => $peer_ids,
                        'message' => $message,
                        'random_id' => 0,
                        'dont_parse_links' => 1
                    ], use_placeholders: false);
                } catch (Exception $e) {
                    $this->send_error_in_vk = false;
                    trigger_error('Не удалось отправить ошибку в ЛС: ' . $e->getMessage(), E_USER_WARNING);
                }
            }
        }
    }

    protected function getCodeSnippet(string $file, int $line, int $padding = 0, bool $with_colors = true): string
    {
        $cache_key = "$file:$line:$padding:" . ($with_colors ? 'color' : 'nocolor');

        if (isset($this->snippet_cache[$cache_key])) {
            return $this->snippet_cache[$cache_key];
        }

        static $files_cache = [];

        if (!isset($files_cache[$file]) && is_readable($file)) {
            $files_cache[$file] = file($file, FILE_IGNORE_NEW_LINES);
        }

        if (!isset($files_cache[$file])) {
            return $this->snippet_cache[$cache_key] = '';
        }

        $lines = $files_cache[$file];
        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);

        if ($start >= $end) {
            return $this->snippet_cache[$cache_key] = '';
        }

        $snippet_lines = [];
        for ($i = $start; $i < $end; $i++) {
            $line_number_text = ($i + 1) . ': ';
            $code_text = trim($lines[$i]);

            if ($with_colors) {
                $line_number = $this->coloredLog($line_number_text, 'YELLOW');
                $code = $this->coloredLog($code_text, 'WHITE');
                $snippet_lines[] = $line_number . $code;
            } else {
                $snippet_lines[] = $line_number_text . $code_text;
            }
        }

        return $this->snippet_cache[$cache_key] = implode(PHP_EOL, $snippet_lines) . PHP_EOL;
    }

    private function normalizeMessage(string $message): string
    {
        return $message;
        /*
        $message = preg_replace('/Array\s*\n?\s*\(/i', '[', $message);

        $message = str_replace(')', ']', $message);

        $lines = explode("\n", $message);

        $result = [];


        foreach ($lines as $line) {
            // Определяем количество пробелов в начале строки
            $leadingSpaces = strspn($line, ' ');

            if ($leadingSpaces > 0) {
                // Базовое сокращение пробелов в половину
                $newIndent = floor($leadingSpaces / 2);

                // Если после пробелов идет '[', уменьшаем еще на 1
                if (isset($line[$leadingSpaces]) && $line[$leadingSpaces] === '[') {
                    $newIndent = max(0, $newIndent + 2);
                }

                // Формируем новую строку с уменьшенным отступом
                $content = substr($line, $leadingSpaces);
                $line = str_repeat(' ', $newIndent) . $content;
            }

            $result[] = rtrim($line); // Удаляем пробелы в конце строки
        }

        return trim(implode("\n", $result));
        */
    }

    private function filterPaths(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        foreach ($this->paths_to_filter as $filter) {
            $path = str_replace($filter, '..', $path);
        }
        return $path;
    }

    private function prepareTraceModel(array $trace_data, string $file, int $line, bool $is_artificial_trace): array
    {
        $first_frame = $trace_data[0] ?? null;

        // Проверяем, существует ли первый кадр и является ли он вызовом обработчика
        // Дополнительно проверяем, является ли это внутренним вызовом (без файла)
        // Это характерно именно для trigger_error
        if ($first_frame && ($first_frame['function'] ?? '') === 'userErrorHandler' && !isset($first_frame['file'])) {
            // Удаляем этот бесполезный первый кадр
            array_shift($trace_data);
        }

        // Иногда основная точка ошибки не является первой в трейсе.
        // Добавляем ее в начало, если это не так.
        if (!$is_artificial_trace && isset($trace_data[0])) {
            $first_trace = $trace_data[0];
            if (($first_trace['file'] ?? '') !== $file || ($first_trace['line'] ?? 0) !== $line) {
                array_unshift($trace_data, ['file' => $file, 'line' => $line]);
            }
        }

        // Если включен режим короткого трейса, фильтруем системные файлы и файлы библиотеки для ВК
        if ($this->short_trace) {
            $trace_data = array_filter($trace_data, static function ($trace_item) {
                if (!isset($trace_item['file'])) {
                    // Всегда оставляем внутренние вызовы PHP, они могут быть важны.
                    return true;
                }
                $normalized_path = str_replace('\\', '/', $trace_item['file']);
                // Фильтруем все, что в /vendor/ или в /simplevk.../src/
                return !preg_match(self::VENDOR_PATH_PATTERN, $normalized_path);
            });
        }

        $trace_model = [];
        foreach ($trace_data as $num => $data) {
            $file_path = $data['file'] ?? null;
            $line_num = $data['line'] ?? null;
            $is_internal = ($file_path === null || $line_num === null);

            $snippet_colored = '';
            $snippet_plain = '';
            if (!$is_internal) {
                $snippet_colored = $this->getCodeSnippet($file_path, $line_num, 0, true);
                $snippet_plain   = $this->getCodeSnippet($file_path, $line_num, 0, false);
            }

            $is_user_file = !$is_internal && !preg_match(self::VENDOR_PATH_PATTERN, str_replace('\\', '/', $file_path));

            $trace_model[] = [
                'num' => $num,
                'file' => $file_path,
                'line' => $line_num,
                'class' => $data['class'] ?? '',
                'function' => $data['function'] ?? '{unknown function}',
                'is_internal' => $is_internal,
                'is_user_file' => $is_user_file,
                'snippet_colored' => $snippet_colored,
                'snippet_plain' => $snippet_plain,
            ];
        }
        return $trace_model;
    }

    /**
     * Рендерит модель трейса в строку с заданными параметрами форматирования.
     *
     * @param array $trace_model Подготовленная модель трейса.
     * @param bool $with_colors Добавлять ли ANSI-коды цветов.
     * @param bool $shorten_paths Сокращать ли пути к файлам.
     * @return string
     */
    private function renderTrace(array $trace_model, bool $with_colors, bool $shorten_paths): string
    {
        $trace_string = '';
        foreach ($trace_model as $frame) {
            $log = fn($text, $color) => $with_colors ? $this->coloredLog($text, $color) : $text;

            if ($frame['is_internal']) {
                $class_function = $frame['class'] ? "{$frame['class']}->{$frame['function']}()" : "{$frame['function']}()";
                $trace_string .= $log("#{$frame['num']} ", 'GREEN')
                    . $log('[internal function]', 'BLUE') . "\n"
                    . $log("?: ", 'YELLOW')
                    . $log($class_function, 'WHITE') . "\n\n";
                continue;
            }

            $user_file_marker = $frame['is_user_file'] ? '➡ ' : '';
            $file_path = $shorten_paths ? $this->filterPaths($frame['file']) : $frame['file'];

            $header = $log("{$user_file_marker}#{$frame['num']} ", 'GREEN')
                . $log($file_path, 'BLUE')
                . $log(":{$frame['line']}", 'YELLOW');

            // Выбираем нужный сниппет (цветной или простой)
            $snippet = $with_colors ? $frame['snippet_colored'] : $frame['snippet_plain'];

            $trace_string .= $header . "\n"
                . ($snippet ? $log($snippet, 'WHITE') . "\n" : "\n");
        }
        return $trace_string;
    }
}