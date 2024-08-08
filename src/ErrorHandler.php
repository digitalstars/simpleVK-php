<?php

namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

trait ErrorHandler {

    private $user_error_hendler_or_ids = null;
    private bool $printException_used = false;

    private function defaultErrorLevelMap(): array {
        return [
            E_ERROR             => 'CRITICAL',         // Критическая ошибка
            E_WARNING           => 'WARNING',          // Предупреждение
            E_PARSE             => 'ERROR',            // Синтаксическая ошибка
            E_NOTICE            => 'NOTICE',           // Замечание
            E_CORE_ERROR        => 'CRITICAL',         // Критическая ошибка в ядре
            E_CORE_WARNING      => 'WARNING',          // Предупреждение в ядре
            E_COMPILE_ERROR     => 'CRITICAL',         // Ошибка компиляции
            E_COMPILE_WARNING   => 'WARNING',          // Предупреждение компиляции
            E_USER_ERROR        => 'ERROR',            // Ошибка пользователя
            E_USER_WARNING      => 'WARNING',          // Предупреждение пользователя
            E_USER_NOTICE       => 'NOTICE',           // Замечание пользователя
            E_STRICT            => 'NOTICE',           // Строгие предупреждения
            E_RECOVERABLE_ERROR => 'ERROR',            // Ошибка, которую можно восстановить
            E_DEPRECATED        => 'NOTICE',           // Устаревшее предупреждение
            E_USER_DEPRECATED   => 'NOTICE',           // Устаревшее предупреждение пользователя
        ];
    }

    private function errorCodesMap(): array {
        return [
            E_ERROR              => 'E_ERROR',
            E_WARNING            => 'E_WARNING',
            E_PARSE              => 'E_PARSE',
            E_NOTICE             => 'E_NOTICE',
            E_CORE_ERROR         => 'E_CORE_ERROR',
            E_CORE_WARNING       => 'E_CORE_WARNING',
            E_COMPILE_ERROR      => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING    => 'E_COMPILE_WARNING',
            E_USER_ERROR         => 'E_USER_ERROR',
            E_USER_WARNING       => 'E_USER_WARNING',
            E_USER_NOTICE        => 'E_USER_NOTICE',
            E_STRICT             => 'E_STRICT',
            E_RECOVERABLE_ERROR  => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED         => 'E_DEPRECATED',
            E_USER_DEPRECATED    => 'E_USER_DEPRECATED',
        ];
    }

    private function user_error_handler($type, $message, $file, $line, $code = null, $exception = null) {
        if (!is_numeric($code)) {
            $code = null;
        }
        // если ошибка попадает в отчет (при использовании оператора "@" error_reporting() вернет 0)
        if (error_reporting() & $type) {
            $error_type = $this->errorCodesMap()[$type];
            $error_level = $this->defaultErrorLevelMap()[$type];

            $error_level_str = match ($error_level) {
                'ERROR', 'CRITICAL' => '‼Fatal Error: ',
                'WARNING' => '⚠️Warning: ',
                'NOTICE' => '⚠️Notice: ',
                default => '‼Unknown Error: ',
            };

            if($this->printException_used) {
                $msg = "$error_level_str $message";
                $this->printException_used = false;
            } else {
                if (!is_readable($file)) {
                    $code_snippet = 'Файл недоступен.';
                } else {
                    $file_lines = file($file);
                    $code_snippet = $this->getCodeSnippet($file_lines, $line);
                }
                $msg = "$error_level_str $message ($file на $line строке)\n➡$code_snippet";
            }

            if (is_callable($this->user_error_hendler_or_ids)) {
                call_user_func($this->user_error_hendler_or_ids, $error_type, $message, $file, $line, $msg, $code, $exception);
            } else {
                $peer_ids = join(',', $this->user_error_hendler_or_ids);
                $this->request('messages.send', ['peer_ids' => $peer_ids, 'message' => $msg, 'random_id' => 0, 'dont_parse_links' => 1], false);
            }
        }
        return TRUE; // не запускаем внутренний обработчик ошибок PHP
    }

    public function setUserLogError($ids) {
        if (is_numeric($ids))
            $ids = [$ids];

        $this->user_error_hendler_or_ids = $ids;

        $fatal_error_handler = function () {
            if ($error = error_get_last() AND $error['type'] & (DEFAULT_ERROR_LOG)) {
                $type = $this->normalization($error['type']);
                $message = $this->normalization($error['message']);
                $file = $this->normalization($error['file']);
                $line = $this->normalization($error['line']);
                $code = $error['code'] ?? null;
                $this->user_error_handler($type, $message, $file, $line, $code); // запускаем обработчик ошибок
            }
        };

        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);

        $error_handler = function ($type, $message, $file, $line) {
            return $this->user_error_handler($type, $message, $file, $line);
        };

        set_error_handler($error_handler);
        set_exception_handler([$this, 'printException']);
        register_shutdown_function($fatal_error_handler);

        return $this;
    }

    private function getCodeSnippet($file_lines, $line_number, $padding = 0) {
        $start = max(0, $line_number - $padding - 1);
        $end = min(count($file_lines), $line_number + $padding);

        $snippet = '';
        for ($i = $start; $i < $end; $i++) {
            $snippet .= ($i + 1) . ': ' . $file_lines[$i];
        }

        return trim($snippet);
    }

    private function normalization($message) {
        $message = str_replace('Stack trace', 'STACK TRACE', $message);
        $message = str_replace("Array\n", 'Array ', $message);
        $message = str_replace("\n)", ')', $message);
        $message = str_replace("\n#", "\n\n#", $message);
        $message = str_replace("): ", "): \n", $message);
        $message = preg_replace_callback("/\n */", static function($search) {
            return "\n&#8288;" . str_repeat("&#8199;", ceil((mb_strlen($search[0])-1)/2));
        }, $message);
        $message = preg_replace_callback('/(?:\\\\x)([0-9A-Fa-f]+)/', static function($matched) {
            return chr(hexdec($matched[1]));
        }, $message);
        return $message;
    }

    public function printException($exception) {
        $this->printException_used = true;
        $message = $this->normalization($exception->getMessage());
        $file = $this->normalization($exception->getFile());
        $line = $this->normalization($exception->getLine());
        $code = $exception->getCode();

        if(str_contains($file, 'vendor/')) {
            $ex = explode("vendor/", $file, 2);
            $trace_zero = "#0 ../".$ex[1]."($line)\n";
        } else {
            $files_code = [];
            if (!is_readable($file)) {
                $code_snippet = 'Файл недоступен.';
            } else {
                $file_lines = file($file);
                $files_code[$file] = $file_lines;
                $code_snippet = $this->getCodeSnippet($file_lines, $line);
            }

            $trace_zero = "➡ #0 " . $file . "($line)\n{$code_snippet}\n";
        }

        $trace = "$trace_zero\n";
        foreach ($exception->getTrace() as $num => $value) {
            $need_ext_trace = 0;
            $new_num = $num + 1;
            $file = $value['file'] ?? 'unknown file';
            $line = $value['line'] ?? '?';
            if(str_contains($file, 'vendor/')) {
                $ex = explode("vendor/", $file, 2);
                $trace .= "#{$new_num} ../".$ex[1]."($line)\n";
            } else {
                if(!isset($files_code[$file])) {
                    if ($file == 'unknown file' || !is_readable($file)) {
                        $file = 'Файл недоступен.';
                        $file_lines = [];
                    } else {
                        $file_lines = file($file);
                        $files_code[$file] = $file_lines;
                    }
                } else {
                    $file_lines = $files_code[$file];
                }
                if(is_numeric($line) && is_array($file_lines)) {
                    $code_snippet = $this->getCodeSnippet($file_lines, $line);
                    $trace .= "➡ #{$new_num} ".$file."($line)\n{$code_snippet}\n";
                } else {
                    $need_ext_trace = 1;
                    $trace .= "➡ #{$new_num} ".$file."($line)\n";
                }
            }

            $type = $value['type'] ?? '';
            $function = $value['function'];
            if($function == '{closure}') {
                $function = "anonFunc";
            }
            $class = $value['class'] ?? '';
            $class = str_replace(["DigitalStars\DataBase\\", "DigitalStars\SimpleVK\\"], "", $class);

            // Add arguments to the trace
            $args = '';
            if (!empty($value['args'])) {
                $args = array_map(static function ($arg) {
                    if (is_object($arg)) {
                        return get_class($arg);
                    }

                    if (is_array($arg)) {
                        return 'Array';
                    }

                    return var_export($arg, true);
                }, $value['args']);
                $args = implode(', ', $args);
            }

            if($need_ext_trace || str_contains($file, 'vendor/')) {
                $trace .= "{$class}{$type}{$function}($args)\n\n";
            }
        }
        $message .= "\n\n$trace";
        $this->user_error_handler(1, $message, $file, $line, $code, $exception); // запускаем обработчик ошибок
    }
}
