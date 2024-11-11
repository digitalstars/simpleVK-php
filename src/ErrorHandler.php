<?php

namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

trait ErrorHandler {

    private mixed $user_error_handler_or_ids = null;

    private array $paths_to_filter = [];

    private bool $short_trace = false;

    /**
     * Устанавливает обработчик ошибок и исключений, перенаправляя их для логирования и вывода.
     * @param int|array<int>|callable $ids VK ID пользователя, массив ID или функция-обработчик.
     * @return self Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function setUserLogError(callable|array|int $ids): self {
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
    public function setTracePathFilter(string|array $pathes): void {
        $pathes = is_string($pathes) ? [$pathes] : $pathes;
        $this->paths_to_filter = array_map(static fn($path) => str_replace('\\', '/', $path), $pathes);
    }

    /**
     * Оставляет в трейсе только пользовательские файлы, без файлов библиотеки
     * @param bool $enable - вкл/выкл отображение короткого трейса
     * @return self Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function shortTrace(bool $enable = true): self {
        $this->short_trace = $enable;
        return $this;
    }

    /**
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     */
    public function exceptionHandler(\Throwable $exception, int $set_type = E_ERROR, bool $need_skip_check_error_without_trace = false): void {
        $message = $this->normalizeMessage($exception->getMessage());
        $message = $this->filterPaths($message);
        $message = $this->coloredLog($message, 'RED');
        $file = $this->normalizeMessage($exception->getFile());
        $line = $this->normalizeMessage($exception->getLine());
        $code = $exception->getCode();
        $trace = $this->buildNewTrace($exception->getTrace(), $file, $line, $need_skip_check_error_without_trace);

        $this->userErrorHandler($set_type, $message . "\n\n\n$trace", $file, $line, $code, $exception, $need_skip_check_error_without_trace);
    }

    /**
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     */
    public function userErrorHandler(int $type, string $message, string $file, int $line, ?int $code = null, ?\Throwable $exception = null, bool $need_skip_check_error_without_trace = false): void {
        // если ошибка попадает в отчет (при использовании оператора "@" error_reporting() вернет 0)
        if (error_reporting() & $type) {
            $error_type_without_trace = [
                E_WARNING,   // Системные предупреждения
                E_NOTICE,    // Системные уведомления
                E_USER_WARNING,   // Пользовательские предупреждения
                E_USER_NOTICE,    // Пользовательские уведомления
                E_USER_ERROR,     // Пользовательские ошибки
                E_DEPRECATED,     // Устаревшие функции
                E_USER_DEPRECATED // Пользовательские устаревшие функции
            ];

            if (!$need_skip_check_error_without_trace && in_array($type, $error_type_without_trace, true)) {
                 //Создаем исключение для типа ошибки, не содержащей полного трейса
                $exception = new SimpleVkException(0, $message);
                $this->exceptionHandler($exception, $type, true); // Передаем оригинальный тип ошибки и получаем полный трейс
                return;
            }

            [$error_level, $error_type] = $this->defaultErrorLevelMap()[$type];
            $error_level_str = $this->formatErrorLevel($error_level);

            $msg = "{$error_level_str}{$message}";
            $msg = str_replace("\n\n", "\n", $msg);
            $msg_for_vk = preg_replace('/\033\[[0-9;]*m/', '', $msg); //Очистка от цвета

            $this->dispatchErrorMessage($error_type, $msg_for_vk, $code, $exception);

            if ($exception) {
                print $msg;
            }
        }
    }

    private function checkForFatalError(): void {
        if ($error = error_get_last()) {
            $type = $error['type'];
            if ($type & DEFAULT_ERROR_LOG) {
                //запускаем обработчик ошибок
                $this->userErrorHandler($type, $error['message'], $error['file'], $error['line']);
            }
        }
    }

    private function defaultErrorLevelMap(): array {
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
            E_STRICT => ['NOTICE', 'E_STRICT'],
            E_RECOVERABLE_ERROR => ['ERROR', 'E_RECOVERABLE_ERROR'],
            E_DEPRECATED => ['NOTICE', 'E_DEPRECATED'],
            E_USER_DEPRECATED => ['NOTICE', 'E_USER_DEPRECATED'],
        ];
    }

    private function formatErrorLevel(string $level): string {
        return match ($level) {
            'ERROR', 'CRITICAL' => $this->coloredLog('‼Fatal Error: ', 'RED'),
            'WARNING' => $this->coloredLog('⚠️Warning: ', 'YELLOW'),
            'NOTICE' => $this->coloredLog('⚠️Notice: ', 'BLUE'),
            default => $this->coloredLog('‼Unknown Error: ', 'RED'),
        };
    }

    private function coloredLog(string $text, string $color) {
        $color_codes = [
            'RED'     => "\033[31m",
            'GREEN'   => "\033[32m",
            'YELLOW'  => "\033[33m",
            'BLUE'    => "\033[34m",
            'WHITE'   => "\033[37m",
        ];
        $color_code = $color_codes[$color];
        return "{$color_code}{$text}\033[0m";
    }

    private function dispatchErrorMessage(string $type, string $message, ?int $code = null, ?\Throwable $exception = null): void {
        if (is_callable($this->user_error_handler_or_ids)) {
            call_user_func($this->user_error_handler_or_ids, $type, $message, $code, $exception);
        } else {
            $peer_ids = implode(',', $this->user_error_handler_or_ids);
            $this->request('messages.send', [
                'peer_ids' => $peer_ids,
                'message' => $message,
                'random_id' => 0,
                'dont_parse_links' => 1
            ], use_placeholders: false);
        }
    }

    protected function getCodeSnippet(string $file, int $line, int $padding = 0): string {
        static $files_cache = [];

        if (!isset($files_cache[$file]) && is_readable($file)) {
            $files_cache[$file] = file($file, FILE_IGNORE_NEW_LINES);
        }

        if (!isset($files_cache[$file])) {
            return 'Файл недоступен.';
        }

        $lines = $files_cache[$file];
        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);
        $snippet = '';

        for ($i = $start; $i < $end; $i++) {
            $line_number = ($i + 1) . ': ';
            $line_number = $this->coloredLog($line_number, 'YELLOW');
            $code = $this->coloredLog(trim($lines[$i]), 'WHITE');
            $snippet .= $line_number . $code . PHP_EOL;
        }

        return $snippet;
    }

    private function normalizeMessage(string $message): string {
        $message = str_replace(['Stack trace', "Array\n", "\n)", "\n#", "): "],
            ['STACK TRACE', 'Array ', ')', "\n\n#", "): \n"], $message);

        return preg_replace_callback( //делим отступы пополам
            "/\n */",
            static function ($search) {
                $indent_size = (int) ceil((mb_strlen($search[0]) - 1) / 2);
                $indent = str_repeat("&#8199;", $indent_size);
                return "\n" . $indent;
            },
            trim($message)
        );
    }

    private function buildNewTrace(array $traceData, string $file, int $line, bool $need_skip_check_error_without_trace): string {
        //если нет трейса, значит мы получили его искуственно и первый элемент трейса не нужен
        $trace = $need_skip_check_error_without_trace ? '' : $this->formatTraceLine(['file' => $file, 'line' => $line], 0);

        foreach ($traceData as $num => $data) {
            $trace .= $this->formatTraceLine($data, $num + 1);
        }

        return $trace;
    }

    private function formatTraceLine(array $trace, int $num): string {
//        $type = $trace['type'] ?? '';
//        $function = $trace['function'] ?? '{unknown function}';
//        $class = $trace['class'] ?? '';
//        $class = str_replace(["DigitalStars\DataBase\\", "DigitalStars\SimpleVK\\"], "", $class);
//        $args = $trace['args'] ?? [];
        $trace_line = '';
        $file = $trace['file'] ?? 'unknown file';
        $line = $trace['line'] ?? '?';

        $formatted_file = $this->filterPaths($file);

        $code_snippet = $this->getCodeSnippet($file, (int)$line);
        $pattern = '#/(simplevk3/src|vendor|simplevk-master/src|simplevk-testing/src)(/.*)#';
        $user_file_marker = '➡ ';

        if (preg_match($pattern, $formatted_file, $matches) || !isset($trace['file'])) {
            $formatted_file = ".." . $matches[0];
            $user_file_marker = '';
            if(!$this->short_trace) {
                $trace_line .= $this->coloredLog("{$user_file_marker}#{$num} ", 'GREEN')
                    . $this->coloredLog($formatted_file, 'BLUE')
                    . $this->coloredLog("($line)", 'YELLOW') . "\n"
                    . $this->coloredLog($code_snippet, 'WHITE') . "\n\n";
            }
        } else {
            $trace_line .= $this->coloredLog("{$user_file_marker}#{$num} ", 'GREEN')
                . $this->coloredLog($formatted_file, 'BLUE')
                . $this->coloredLog("($line)", 'YELLOW') . "\n"
                . $this->coloredLog($code_snippet, 'WHITE') . "\n\n";
        }

        return $trace_line;
    }

    private function filterPaths(string $path): string {
        $path = str_replace('\\', '/', $path);
        foreach ($this->paths_to_filter as $filter) {
            $path = str_replace($filter, '..', $path);
        }
        return $path;
    }
    /*
    private function formatArgs(array $args) {
        return implode(', ', array_map(static function ($arg) {
            if(is_object($arg)) {
                return get_class($arg);
            }

            return is_array($arg) ? 'Array' : var_export($arg, true);
        }, $args));
    } */
}