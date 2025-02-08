<?php

namespace DigitalStars\SimpleVK;

use Exception;
use Throwable;
use RuntimeException;

require_once('config_simplevk.php');

class SimpleVkException extends Exception {
    /**
     * Флаг, определяющий, нужно ли логировать ошибки.
     *
     * @var bool
     */

    private static bool $log_errors = true;
    /**
     * Директория для хранения файлов логов.
     *
     * @var ?string
     */
    private static ?string $error_dir_path = null;

    public function __construct(int $code, string $message, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Выключить логирование ошибок в файл.
     * По умолчанию ошибки записываются
     * @return void
     */
    public static function disableWriteError(): void {
        self::$log_errors = false;
    }

    /**
     * Устанавливает путь к директории для логов (относительный или абсолютный).
     * @param string $path
     * @return void
     */
    public static function setErrorDirPath(string $path): void {
        $real_path = realpath($path);
        if ($real_path === false) {
            self::ensureErrorDirectoryExists($path);
            $real_path = realpath($path);
        }

        self::$error_dir_path = $real_path;
    }

    /**
     * Логирует произвольное сообщение об ошибке.
     *
     * @param string $message Сообщение.
     * @return void
     */
    public static function logCustomError(string $message): void {
        if(self::$log_errors) {
            self::ensureErrorDirectoryExists();
            $log_message = self::formatCustomError($message);
            self::appendLog($log_message);
        }
    }

    /**
     * Возвращает корневую директорию, основываясь на параметрах запуска.
     *
     * @return string
     */
    private static function getRootDirectory(): string {
        if (!empty($_SERVER['SCRIPT_FILENAME'])) { //CLI + Webserver, но не любой
            return dirname(realpath($_SERVER['SCRIPT_FILENAME']));
        }

        if (!empty($_SERVER['argv'][0])) { //CLI 100% получение
            return dirname(realpath($_SERVER['argv'][0]));
        }

        return getcwd(); //крайний вариант директория, в которой выполняют команду
    }

    /**
     * Форматирует сообщение для произвольного лога.
     *
     * @param string $message Сообщение.
     * @return string
     */
    private static function formatCustomError(string $message): string {
        return sprintf(
            "[Exception] %s\n%s\n\n",
            date("d.m.Y H:i:s"),
            $message
        );
    }

    private static function appendLog(string $log_message): void {
        if(!self::$error_dir_path) {
            self::$error_dir_path = self::getRootDirectory() . DIRECTORY_SEPARATOR . 'errors';
        }
        $log_file = self::$error_dir_path . DIRECTORY_SEPARATOR . "error_log" . date('Y-m-d') . ".php";

        self::createLogFileIfNotExists($log_file);
        if (@file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX) === false) {
            self::throwLogCreationError(custom_message: "По какой-то причине не удалось записать ошибку в файл лога по пути:\n$log_file");
        }
    }

    /**
     * Создаёт файл лога, если он не существует.
     *
     * @param string $file_path Путь к файлу.
     * @return void
     */
    private static function createLogFileIfNotExists(string $file_path): void {
        if (!file_exists($file_path)) {
            $header = "<?php http_response_code(404); exit('404'); ?>\nLOGS:\n\n";
            if (@file_put_contents($file_path, $header, FILE_APPEND | LOCK_EX) === false) {
                self::throwLogCreationError('файл', $file_path);
            }
        }
    }

    /**
     * Проверяет наличие директории для логов и создаёт её, если необходимо.
     *
     * @param string|null $dir_path Необязательный путь к директории.
     * @throws RuntimeException Если создать директорию не удалось.
     * @return void
     */
    private static function ensureErrorDirectoryExists(?string $dir_path = null): void {
        if(!self::$error_dir_path) {
            self::$error_dir_path = self::getRootDirectory() . DIRECTORY_SEPARATOR . 'errors';
        }
        $directory = $dir_path ?? self::$error_dir_path;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::throwLogCreationError('директорию', $directory);
        }
    }

    private static function throwLogCreationError(?string $object = null, ?string $path = null, ?string $custom_message = null): void {
        if($custom_message) {
            $message = $custom_message;
        } else {
            $directory = dirname($path);
            $message = sprintf(
                "Не удалось создать %s логов ошибок по пути:\n%s\n\n" .
                "Возможные варианты решения:\n" .
                "- 1. Измените права доступа у директории %s\n" .
                "- 2. Переназначьте директорию для логирования, вызвав SimpleVkException::setErrorDirPath(), и укажите путь к директории\n" .
                "- 3. Отключите логирование ошибок в файл, вызвав SimpleVkException::disableWriteError()\n",
                $object, $path, $directory
            );
        }

        self::disableWriteError(); //выключаем, чтобы не появилась бесконечная рекурсия попыток записи ошибки
        throw new RuntimeException($message);
    }

}
