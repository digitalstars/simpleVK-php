<?php

namespace DigitalStars\SimpleVK;

/**
 * Конструктор элемента карусели.
 */
class Carousel {
    /** @var array Конфиг элемента (по ссылке на конфиг сообщения). */
    private array $config;
    private ?Message $msg;

    public function __construct(&$config = [], ?Message $msg = null) {
        $this->config = &$config;
        if (empty($this->config['action']))
            $this->config['action'] = ['type' => 'open_photo'];
        $this->msg = $msg;
    }

    public static function create(&$config = [], ?Message $msg = null): static {
        return new self($config, $msg);
    }

    public function title(string $title): static {
        $this->config['title'] = $title;
        return $this;
    }

    public function getTitle(): string {
        return $this->config['title'];
    }

    public function description(string $description): static {
        $this->config['description'] = $description;
        return $this;
    }

    public function getDescription(): string {
        return $this->config['description'];
    }

    /** Путь/URL изображения (загружается при отправке). */
    public function img(string $img): static {
        $this->config['img'] = $img;
        return $this;
    }

    /** Готовое вложение photo{id}_{id} (взаимоисключимо с img()). */
    public function attachment(string $attachment): static {
        $this->config['attachment'] = $attachment;
        return $this;
    }

    public function getImg(): string {
        return $this->config['img'];
    }

    /** Действие по нажатию: без ссылки — открыть фото, с ссылкой — open_link. */
    public function action(string $link = ''): static {
        if ($link === '')
            $this->config['action'] = ['type' => 'open_photo'];
        else
            $this->config['action'] = ['type' => 'open_link', 'link' => $link];
        return $this;
    }

    /** @return string|false Ссылка кнопки действия или false. */
    public function getAction(): string|false {
        return $this->config['action']['link'] ?? false;
    }

    /**
     * Кнопки карточки: строка-кнопка Bot или массив таких строк.
     *
     * @param string|array $kbd
     */
    public function kbd(string|array $kbd): static {
        if (is_string($kbd))
            $kbd = [$kbd];
        $this->config['kbd'] = $kbd;
        return $this;
    }

    public function getKbd(): array {
        return $this->config['kbd'];
    }

    public function dump(): array {
        return $this->config;
    }

    /** Заменяет конфиг целиком. */
    public function load(array $config): static {
        $this->config = $config;
        return $this;
    }

    /**
     * Возвращает родительское сообщение для продолжения цепочки.
     * @throws SimpleVkException если карусель создана вне Message::carousel().
     */
    public function save(): Message {
        if (isset($this->msg))
            return $this->msg;
        throw new SimpleVkException(0, "Карусель создана без привязки к сообщению");
    }
}
