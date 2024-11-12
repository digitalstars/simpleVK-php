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
    private static bool $write_error = true;
    private static string $error_dir_path = '';

    public function __construct(int $code, string $message, Throwable $previous = null) {
        if (self::$write_error) {
            self::ensureErrorDirectoryExists();
            $this->logError($code, $message);
        }
        parent::__construct($message, $code, $previous);
    }

    /**
     * Выключить логирование ошибок в файл.
     * По умолчанию ошибки записываются
     * @return void
     */
    public static function disableWriteError(){
        self::$write_error = false;
    }

    /**
     * Устанавливает относительный или абсолютный путь до папки, куда будут логироваться ошибки
     * @param string $path
     * @return void
     */
    public static function setErrorDirPath(string $path): void {
        $real_path = realpath($path);
        if (!$real_path) {
            self::ensureErrorDirectoryExists($path);
            $real_path = realpath($path);
        }

        self::$error_dir_path = $real_path;
    }

    public static function logCustomError(string $message): void {
        self::ensureErrorDirectoryExists();
        self::writeToLog($message);
    }

    private function logError(int $code, string $message): void {
        $error_message = sprintf(
            "[Exception] %s\nCODE: %d\nMESSAGE: %s\nin: %s:%d\nStack trace:\n%s\n\n",
            date("d.m.y H:i:s"),
            $code,
            $message,
            $this->getFile(),
            $this->getLine(),
            $this->getTraceAsString()
        );
        self::writeToLogFile($error_message);
    }

    private static function writeToLog(string $message): void {
        $error_message = sprintf(
            "[Exception] %s\nMESSAGE: %s\n\n",
            date("d.m.y H:i:s"),
            $message
        );
        self::writeToLogFile($error_message);
    }

    private static function writeToLogFile(string $message): void {
        $path = self::$error_dir_path . "/error_log" . date('Y-m-d') . ".php";
        self::createLogFileIfNotExists($path);
        file_put_contents($path, $message, FILE_APPEND | LOCK_EX);
    }

    private static function createLogFileIfNotExists(string $path): void {
        if (!file_exists($path)) {
            file_put_contents(
                $path,
                "<?php http_response_code(404);exit(\"404\");?>\nLOGS:\n\n",
                LOCK_EX
            );
        }
    }

    /**
     * Проверка и создание папки error, если она отсутствует
     */
    private static function ensureErrorDirectoryExists(?string $path = null): void {
        if(!self::$error_dir_path) {
            self::$error_dir_path = getcwd() . DIRECTORY_SEPARATOR. 'error';
        }
        $path = $path ?: self::$error_dir_path;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Не удалось создать директорию для ошибок по пути %s', $path));
            }
        }
    }
}
