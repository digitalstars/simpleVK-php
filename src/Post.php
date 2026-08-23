<?php

namespace DigitalStars\SimpleVK;

/**
 * Конструктор и отправитель записей на стене (wall.post).
 */
class Post extends BaseConstructor
{
    /** @param array|null $cfg */
    public static function create($vk = null, &$cfg = null): static
    {
        return new self($vk, $cfg);
    }

    /**
     * Загружает конфиг из другого Post или массива.
     *
     * @param Post|array $cfg
     * @return static
     */
    public function load($cfg = []): static
    {
        if ($cfg instanceof Post) {
            $this->vk = $cfg->vk;
            $this->config = $cfg->config;
        } else
            $this->config = $cfg;
        return $this;
    }

    /**
     * Публикует запись на стене.
     *
     * @param int|string|null $id owner_id (по умолчанию id текущего пользователя).
     * @param int|null $publish_date Timestamp отложенной публикации (только в будущем).
     * @return mixed Результат wall.post.
     */
    public function send($id = null, ?int $publish_date = null, $vk = null)
    {
        $params = [];
        if (!is_null($publish_date)) {
            if ($publish_date >= time())
                $params['publish_date'] = $publish_date;
            else
                throw new SimpleVkException(0, 'Неверно указан $publish_date');
        }

        if (!empty($this->config['real_id']))
            $id = $this->config['real_id'];

        if (empty($this->vk) and isset($vk))
            $this->vk = $vk;
        if (empty($this->vk))
            throw new SimpleVkException(0, 'Экземпляр SimpleVK не передан');
        if (empty($id)) {
            $id = $this->vk->userInfo()['id'];
        }
        $this->config_cache = $this->config;
        if ($this->preProcessing(null)) // вернет true, если замыкание события прервало выполнение
            return null;

        if (!empty($this->config['real_id']))
            $id = $this->config['real_id'];

        $attachments = [];
        if (isset($this->config['img']))
            foreach ($this->config['img'] as $img)
                $attachments[] = $this->vk->getWallAttachmentUploadImage($id, $img[0]);
        if (isset($this->config['doc']))
            foreach ($this->config['doc'] as $doc)
                $attachments[] = $this->vk->getWallAttachmentUploadDoc($id, $doc[0], $doc[1]);
        if (isset($this->config['attachments']))
            $attachments = array_merge($attachments, $this->config['attachments']);
        if (isset($this->config['params']['attachment'])) {
            $attachments = array_merge($attachments, $this->config['params']['attachment']);
            unset($this->config['params']['attachment']);
        }
        $attachments = !empty($attachments) ? ['attachment' => join(',', $attachments)] : [];

        if (isset($this->config['params']))
            $params += $this->config['params'];
        $text = isset($this->config['text']) ? ['message' => $this->config['text']] : [];
        $query = $text + $params + $attachments;
        if (empty($query))
            $result = null;
        else
            $result = $this->request('wall.post', ['owner_id' => $id] + $query);
        $this->postProcessing($id, $result, null);
        return $result;
    }
}
