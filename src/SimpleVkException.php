<?php

namespace DigitalStars\SimpleVK;

use Exception;
use Throwable;

require_once('config_simplevk.php');

class SimpleVkException extends Exception {
    /**
     * Логирование ошибок в файл
     * @var true
     */
    private static $write_error = true;

    public function __construct($code, $message, Throwable $previous = null) {
        if(self::$write_error === true) {
            if (!is_dir(DIRNAME . '/error')) {
                if (mkdir($concurrentDirectory = DIRNAME . '/error') || !is_dir($concurrentDirectory)) {
                    $this->printError($code, $message);
                }
            } else
                $this->printError($code, $message);
        }

        parent::__construct($message, $code, $previous);
    }

    public static function userError(SimpleVkException $e) {
        $error = "[Exception] " . date("d.m.y H:i:s");
        $error .= "\r\n{$e->getMessage()}";
        $error .= "\r\nin: {$e->getFile()}:{$e->getLine()}";
        $error .= "\r\nStack trace:\r\n{$e->getTraceAsString()}\r\n\r\n";
        return $error;
    }

    public static function nullError($message) {
        if (!is_dir('error')) {
            if (mkdir('error') || is_dir('error')) {
                self::writeToLog($message);
            }
        } else
            self::writeToLog($message);
    }

    private function printError($code, $message) {
        $error = "[Exception] " . date("d.m.y H:i:s");
        $error .= "\r\nCODE: {$code}";
        $error .= "\r\nMESSAGE: {$message}";
        $error .= "\r\nin: {$this->getFile()}:{$this->getLine()}";
        $error .= "\r\nStack trace:\r\n{$this->getTraceAsString()}\r\n\r\n";
        $path = DIRNAME . '/error/error_log' . date('d-m-Y') . ".php";
        SimpleVkException::createNewLogFile($path);
        $file = fopen($path, 'a');
        fwrite($file, $error);
        fclose($file);
    }

    private static function writeToLog($message) {
        $error = "[Exception] " . date("d.m.y H:i:s");
        $error .= "\r\nMESSAGE: {$message}\r\n\r\n";
        $path = DIRNAME . '/error/error_log' . date('d-m-Y_h') . ".php";
        SimpleVkException::createNewLogFile($path);
        $file = fopen($path, 'a');
        fwrite($file, $error);
        fclose($file);
    }

    private static function createNewLogFile($path) {
        if (!file_exists($path))
            file_put_contents($path, "<?php http_response_code(404);exit(\"404\");?>\r\nLOGS:\r\n\r\n");
    }

    /**
     * Выключить логирование ошибок в файл
     * По умолчанию ошибки записываются
     */
    public static function disableWriteError(){
        self::$write_error = false;
    }
}
