<?php

namespace DigitalStars\SimpleVK;

/**
 * Простой файловый key-value стор с блокировкой (для состояния бота).
 * Файлы хранятся в Store::$path и защищены php-заглушкой от чтения напрямую.
 */
class Store
{
    /** @var array|null */
    public $data = null;
    public static string $path = __DIR__ . '/cache';
    /** @var resource|null */
    private $file;
    private string $full_path;
    private bool $is_writable = false;

    public function __construct(string|int $filename = 0)
    {
        if (!is_dir(self::$path))
            mkdir(self::$path, 0775, true);

        $this->full_path = self::$path . '/' . $filename . '.php';
        $this->file = fopen($this->full_path, 'c+');
        if (!flock($this->file, LOCK_SH))
            throw new SimpleVkException(0, 'Не удалось захватить файл');
        $line = '';
        fgets($this->file); // пропускаем php-заглушку
        while (!feof($this->file))
            $line .= fgets($this->file);
        $decoded = json_decode((string) $line, true);
        $this->data = is_array($decoded) ? $decoded : [];
    }

    public static function load(string|int $filename = 0): static
    {
        return new self($filename);
    }

    /**
     * Записывает данные на диск (если был взят write-lock).
     */
    public function save(): static
    {
        if (isset($this->data) && $this->is_writable) {
            ftruncate($this->file, 0);
            rewind($this->file);
            fwrite(
                $this->file,
                "<?php http_response_code(404);exit('404');?>\n"
                    . json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            fflush($this->file);
            // Даунгрейд EX -> SH и сброс флага: в оригинале флаг is_writable оставался true,
            // хотя эксклюзивная блокировка уже была снята
            flock($this->file, LOCK_SH);
        }
        $this->is_writable = false;
        return $this;
    }

    /**
     * Сохраняет данные, снимает блокировку и закрывает файл.
     */
    public function close(): void
    {
        $this->save();
        if ($this->file !== null) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
            $this->file = null;
        }
        unset($this->data);
    }

    public function __destruct()
    {
        if ($this->file !== null) {
            $this->close();
        }
    }

    /** @return mixed */
    public function get(string|int $key)
    {
        return $this->data[$key] ?? null;
    }

    /** Устанавливает значение (с захватом write-lock). */
    public function set(string|int $key, $val): static
    {
        $this->getWriteLock();
        $this->data[$key] = $val;
        return $this;
    }

    public function unset(string|int $key): static
    {
        $this->getWriteLock();
        unset($this->data[$key]);
        return $this;
    }

    /** Устанавливает значение и сразу сохраняет на диск. */
    public function sset(string|int $key, $val): void
    {
        $this->set($key, $val);
        $this->save();
    }

    /**
     * Захватывает эксклюзивную блокировку для записи.
     */
    public function getWriteLock(): static
    {
        if ($this->is_writable)
            return $this;
        if (!flock($this->file, LOCK_EX))
            throw new SimpleVkException(0, 'Не удалось захватить файл');
        $this->is_writable = true;
        return $this;
    }

    /**
     * Удаляет файл хранилища.
     */
    public function clear(): void
    {
        $this->getWriteLock();
        unlink($this->full_path);
    }
}
