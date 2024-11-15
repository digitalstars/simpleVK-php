<?php

namespace DigitalStars\SimpleVK;

class Store {
    public $data = null;
    public static $path = __DIR__ . "/cache";
    private $file;
    private $full_path;
    private $is_writable = false;

    public function __construct($filename = 0) {
        $this->full_path = self::$path . "/" . $filename . ".php";
        if (!is_dir(self::$path))
            mkdir(self::$path);

        $this->file = fopen($this->full_path, 'c+');
        if (!flock($this->file, LOCK_SH))
            throw new SimpleVkException(0, "Не удалось захватить файл");
        $line = '';
        fgets($this->file);
        while (!feof($this->file))
            $line .= fgets($this->file);
        $this->data = json_decode($line, true);
        if (!is_array($this->data))
            $this->data = [];
    }

    public static function load($filename = 0) {
        return new self($filename);
    }

    public function save() {
        if (isset($this->data)) {
            if ($this->is_writable) {
                ftruncate($this->file, 0);
                rewind($this->file);
                fwrite($this->file, "<?php http_response_code(404);exit('404');?>\n" . json_encode($this->data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        if (!flock($this->file, LOCK_SH))
            throw new SimpleVkException(0, "Не удалось захватить файл");
        $this->is_writable = false;
    }

    public function close() {
        $this->save();
        flock($this->file, LOCK_UN);
        fclose($this->file);
        unset($this->data);
    }

    public function __destruct() {
        $this->close();
    }

    public function get($key) {
        return $this->data[$key] ?? null;
    }

    public function set($key, $val) {
        $this->getWriteLock();
        $this->data[$key] = $val;
        return $this;
    }

    public function unset($key) {
        $this->getWriteLock();
        unset($this->data[$key]);
        return $this;
    }

    public function sset($key, $val) {
        $this->set($key, $val);
        $this->save();
    }

    public function getWriteLock() {
        if ($this->is_writable)
            return $this;
        if (!flock($this->file, LOCK_EX))
            throw new SimpleVkException(0, "Не удалось захватить файл");
        $this->is_writable = true;
        return $this;
    }

    public function clear() {
        $this->getWriteLock();
        unlink($this->full_path);
    }
}
