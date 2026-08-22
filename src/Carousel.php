<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

class Carousel
{
    private $config;
    private $msg;

    public function __construct(&$config = [], $msg = null)
    {
        $this->config = &$config;
        if (empty($this->config['action']))
            $this->config['action'] = ['type' => 'open_photo'];
        $this->msg = $msg;
    }

    public static function create(&$config = [], $msg = null)
    {
        return new self($config, $msg);
    }

    public function title($title)
    {
        $this->config['title'] = $title;
        return $this;
    }

    public function getTitle()
    {
        return $this->config['title'];
    }

    public function description($description)
    {
        $this->config['description'] = $description;
        return $this;
    }

    public function getDescription()
    {
        return $this->config['description'];
    }

    public function img($img)
    {
        $this->config['img'] = $img;
        return $this;
    }

    public function attachment($attachment)
    {
        $this->config['attachment'] = $attachment;
        return $this;
    }

    public function getImg()
    {
        return $this->config['img'];
    }

    public function action($link = '')
    {
        if (empty($link))
            $this->config['action'] = ['type' => 'open_photo'];
        else
            $this->config['action'] = ['type' => 'open_link', 'link' => $link];
        return $this;
    }

    public function getAction()
    {
        return $this->config['action']['link'] ?? false;
    }

    public function kbd($kbd)
    {
        if (is_string($kbd))
            $kbd = [$kbd];
        $this->config['kbd'] = $kbd;
        return $this;
    }

    public function getKbd()
    {
        return $this->config['kbd'];
    }

    public function dump()
    {
        return $this->config;
    }

    public function load($config)
    {
        $this->config = $config;
        return $this;
    }

    /** @return Message */
    public function save()
    {
        if (isset($this->msg))
            return $this->msg;
        else
            return null;
    }
}
