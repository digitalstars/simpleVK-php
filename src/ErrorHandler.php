<?php

namespace DigitalStars\SimpleVK;

require_once('config_simplevk.php');

trait ErrorHandler {

    private $user_error_hendler_or_ids = null;
    private $printException_used = false;

    private function user_error_handler($type, $message, $file, $line, $code = null, $exception = null) {
        if (!is_numeric($code))
            $code = null;
        // если ошибка попадает в отчет (при использовании оператора "@" error_reporting() вернет 0)
        if (error_reporting() & $type) {
            $errors = [
                E_ERROR => 'E_ERROR',
                E_WARNING => 'E_WARNING',
                E_PARSE => 'E_PARSE',
                E_NOTICE => 'E_NOTICE',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_CORE_WARNING => 'E_CORE_WARNING',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                E_USER_ERROR => 'E_USER_ERROR',
                E_USER_WARNING => 'E_USER_WARNING',
                E_USER_NOTICE => 'E_USER_NOTICE',
                E_STRICT => 'E_STRICT',
                E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                E_DEPRECATED => 'E_DEPRECATED',
                E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            ];

            $error_type = $errors[$type]."[$type]";
            if ($error_type == 'E_ERROR[1]') {
                $error_type = '‼Fatal error: ';
            }

            if($this->printException_used) {
                $msg = "$error_type $message";
                $this->printException_used = false;
            } else {
                $msg = "$error_type $message ($file на $line строке)";
            }

            if (is_callable($this->user_error_hendler_or_ids)) {
                call_user_func_array($this->user_error_hendler_or_ids, [$errors[$type], $message, $file, $line, $msg, $code, $exception]);
            } else { //тут может не отправиться из-за того, что сообщение слишком длинное
                $this->request('messages.send', ['peer_ids' => $this->user_error_hendler_or_ids, 'message' => $msg, 'random_id' => 0, 'dont_parse_links' => 1]);
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

    private function normalization($message) {
        $message = str_replace('Stack trace', 'STACK TRACE', $message);
        $message = str_replace("Array\n", 'Array ', $message);
        $message = str_replace("\n)", ')', $message);
        $message = str_replace("\n#", "\n\n#", $message);
        $message = str_replace("): ", "): \n", $message);
        $message = preg_replace_callback("/\n */", function($search) {
            return "\n&#8288;" . str_repeat("&#8199;", ceil((mb_strlen($search[0])-1)/2));
        }, $message);
        $message = preg_replace_callback('/(?:\\\\x)([0-9A-Fa-f]+)/', function($matched) {
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

        if(strpos($file, 'vendor/') !== FALSE) {
            $ex = explode("vendor/", $file, 2);
            $trace_zero = "#0 ../".$ex[1]."($line)\n";
        } else {
            $trace_zero = "➡ #0 ".$file."($line)\n";
        }

        $trace = "$trace_zero\n";
        foreach ($exception->getTrace() as $num => $value) {
            $new_num = $num + 1;
            $str_file = $value['file'] ?? 'unknown file';
            $str_line = $value['line'] ?? '?';
            if(strpos($str_file, 'vendor/') !== FALSE) {
                $ex = explode("vendor/", $str_file, 2);
                $trace .= "#{$new_num} ../".$ex[1]."($str_line)\n";
            } else {
                $trace .= "➡ #{$new_num} ".$str_file."($str_line)\n";
            }

            $type = $value['type'] ?? '';
            $function = $value['function'];
            if($function == '{closure}') {
                $function = "anonFunc";
            }
            $class = $value['class'] ?? '';
            $class = str_replace("DigitalStars\DataBase\\", "", $class);
            $class = str_replace("DigitalStars\SimpleVK\\", "", $class);

            // Add arguments to the trace
            $args = '';
            if (!empty($value['args'])) {
                $args = array_map(function ($arg) {
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

            $trace .= "{$class}{$type}{$function}($args)\n\n";
        }
        $message .= "\n\n$trace";
        $this->user_error_handler(1, $message, $file, $line, $code, $exception); // запускаем обработчик ошибок
    }
}
