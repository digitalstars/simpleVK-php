<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Attributes\AsButton;
use DigitalStars\SimpleVK\EventDispatcher\BaseButton;

class Message extends BaseConstructor {
    use FileUploader;

    public static function create($vk = null, &$cfg = null) {
        return new self($vk, $cfg);
    }

    public function voice($path): Message {
        $this->config['voice'] = $path;
        return $this;
    }

    public function getVoice() {
        return $this->config['voice'] ?? null;
    }

    public function load($cfg = []) {
        if ($cfg instanceof MessageBot) {
            return MessageBot::create($cfg->vk, $cfg->config, $cfg->bot, $cfg->buttons, $cfg->id_action);
        }
        if ($cfg instanceof self) {
            $this->vk = $cfg->vk;
            $this->config = $cfg->config;
        } else {
            $this->config = $cfg;
        }
        return $this;
    }

    public function kbd(array|string|object $kbd = [], int|bool $inline = false, bool $one_time = false) {
        $is_invalid_kbd = is_string($kbd);
        if (is_object($kbd)) {
            $kbd = [[$kbd]];
        }
        else if (isset($kbd[0]) and is_string($kbd[0]))
            $kbd = [[$kbd]];
        else if (!$is_invalid_kbd)
            foreach ($kbd as $row)
                foreach ($row as $col)
                    if (is_string($col))
                        $is_invalid_kbd = true;
        if ($is_invalid_kbd)
            throw new SimpleVkException(0, "Класс simpleVK не имеет доступ к указанным в kbd() кнопкам, потому что они созданы классом Bot. Используйте отправку сообщения через класс bot");
        $this->config['kbd'] = ['kbd' => $kbd, 'inline' => (bool)$inline, 'one_time' => $one_time];
        return $this;
    }

    public function getKbd() {
        return $this->config['kbd'] ?? null;
    }

    public function forward($message_ids = null, $conversation_message_ids = null, $peer_id = null, $owner_id = null) {
        if ($message_ids == null && $conversation_message_ids == null) {
            $this->config['forward'] = ['forward' => []];
            return $this;
        }

        $ids = $message_ids ?: $conversation_message_ids;
        $forward_messages = (is_array($ids)) ? join(',', $ids) : $ids;
        if ($conversation_message_ids == null && $peer_id == null && $owner_id == null)
            $this->config['forward'] = ['forward_messages' => $forward_messages];
        else {
            $this->config['forward'] = ['forward' => [
                ($message_ids ? 'message_ids' : 'conversation_message_ids') => $forward_messages,
                'peer_id' => $peer_id]];
            if ($owner_id)
                $this->config['forward']['forward']['owner_id'] = $owner_id;
        }
        return $this;
    }

    public function getForward() {
        return $this->config['forward']['forward_messages'] ?? $this->config['forward']['forward'] ?? null;
    }

    public function clearForward() {
        $this->config['forward'] = [];
        return $this;
    }

    public function reply($message_id = null, $conversation_message_id = null, $peer_id = null) {
        if ($message_id == null && $conversation_message_id == null) {
            $this->config['forward'] = ['forward' => ['is_reply' => true]];
            return $this;
        }
        $this->config['forward'] = ['forward' => [
            ($message_id ? 'message_ids' : 'conversation_message_ids') => $message_id ?: $conversation_message_id,
            'is_reply' => true] + (($peer_id) ? ['peer_id' => $peer_id] : [])];
        return $this;
    }

    public function carousel() {
        $config = [];
        $this->config['carousel'][] = &$config;
        return Carousel::create($config, $this);
    }

    public function setCarousel($carousel) {
        if ($carousel instanceof Carousel)
            $carousel = [$carousel];
        foreach ($carousel as $element)
            $this->config['carousel'][] = $element->dump();
        return $this;
    }

    public function clearCarousel() {
        $this->config['carousel'] = [];
        return $this;
    }

    public function uploadAllImages() {
        $images = [];
        foreach ($this->config['img'] ?? [] as $img_path) {
            $img_path = $img_path[0];
            $images[] = $this->getMsgAttachmentUploadImage(0, $img_path);
        }
        $this->config['img'] = [];
        $this->config['attachments'] = array_merge($this->config['attachments'] ?? [], $images);
        return $this;
    }

    protected function parseKbd($kbd) {
        return $kbd;
    }

    private function parseKeyboard($keyboard_raw = []) {
        $keyboard = [];
        foreach ($keyboard_raw as $row => $button_str) {
            foreach ($button_str as $col => $button) {

                if ($button instanceof BaseButton) {

                    $reflection = new \ReflectionClass($button);
                    $asButtonAttr = $reflection->getAttributes(AsButton::class)[0] ?? null;

                    // Если у класса нет атрибута #[AsButton], а он нужен для UI, пропускаем его.
                    // Это защита от случайной передачи, например, BaseCommand в клавиатуру.
                    if (!$asButtonAttr && !$button->getLabel()) {
                        continue;
                    }

                    // 1. Определяем UI-параметры (приоритет у динамических свойств)
                    if ($asButtonAttr) {
                        $btnUI = $asButtonAttr->newInstance();
                        $label = $button->getLabel() ?? $btnUI->label;
                        $color = $button->getColor() ?? $btnUI->color;
                        $type  = $button->getType()  ?? $btnUI->type;
                        $actionName = $btnUI->payload ?? $reflection->getShortName();
                    } else {
                        // Если атрибута нет, все параметры должны быть заданы динамически
                        $label = $button->getLabel();
                        $color = $button->getColor();
                        $type  = $button->getType();
                        $actionName = $reflection->getShortName();
                    }

                    // Если после всех проверок нет текста на кнопке, она невалидна
                    if (!$label) continue;

                    // 2. Собираем payload
                    $finalPayload = $button->getPayload(); // Берем кастомный payload из Action
                    $finalPayload['action'] = $actionName; // Добавляем наш action_id

                    // 3. Собираем кнопку в формате, понятном VK API
                    $vkButton = [
                        'action' => [
                            'type'    => $type,
                            'payload' => $finalPayload ? json_encode($finalPayload, JSON_UNESCAPED_UNICODE) : null,
                            'label'   => $label,
                        ],
                        'color' => SimpleVK::$color_replacer[$color] ?? $color
                    ];

                    // 4. Очищаем от null-значений, чтобы не отправлять их в API
                    $vkButton['action'] = array_filter(
                        $vkButton['action'],
                        static fn($value) => !is_null($value)
                    );

                    $keyboard[$row][$col] = $vkButton;

                    continue; // Переходим к следующей кнопке
                }

                $keyboard[$row][$col]['action']['type'] = $button[0];
                if ($button[1] != null)
                    $keyboard[$row][$col]['action']['payload'] = json_encode($button[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                switch ($button[0]) {
                    case 'callback':
                    case 'text':
                    {
                        $keyboard[$row][$col]['color'] = $button[3];
                        $keyboard[$row][$col]['action']['label'] = $button[2];
                        break;
                    }
                    case 'vkpay':
                    {
                        $keyboard[$row][$col]['action']['hash'] = "action={$button[2]}";
                        $keyboard[$row][$col]['action']['hash'] .= ($button[3] < 0) ? "&group_id=" . $button[3] * -1 : "&user_id={$button[3]}";
                        $keyboard[$row][$col]['action']['hash'] .= (isset($button[4])) ? "&amount={$button[4]}" : '';
                        $keyboard[$row][$col]['action']['hash'] .= (isset($button[5])) ? "&description={$button[5]}" : '';
                        $keyboard[$row][$col]['action']['hash'] .= (isset($button[6])) ? "&data={$button[6]}" : '';
                        $keyboard[$row][$col]['action']['hash'] .= '&aid=1';
                        break;
                    }
                    case 'open_app':
                    {
                        $keyboard[$row][$col]['action']['label'] = $button[2];
                        $keyboard[$row][$col]['action']['app_id'] = $button[3];
                        if (isset($button[4]))
                            $keyboard[$row][$col]['action']['owner_id'] = $button[4];
                        if (isset($button[5]))
                            $keyboard[$row][$col]['action']['hash'] = $button[5];
                        break;
                    }
                    case 'open_link':
                    {
                        $keyboard[$row][$col]['action']['link'] = $button[2];
                        $keyboard[$row][$col]['action']['label'] = $button[3];
                        break;
                    }
                }
            }
        }
        return $keyboard;
    }

    private function generateCarousel($carousels, $id) {
        if (!is_array($carousels))
            $carousels = [$carousels];
        $template = ["type" => 'carousel', 'elements' => []];
        foreach ($carousels as $carousel) {
            if ($carousel instanceof Carousel)
                $carousel = $carousel->dump();
            $element['action'] = $carousel['action'];
            if (isset($carousel['kbd']))
                $element['buttons'] = $this->parseKeyboard([$carousel['kbd']])[0];
            if (isset($carousel['title']))
                $element['title'] = $carousel['title'];
            if (isset($carousel['description']))
                $element['description'] = $carousel['description'];
            if (isset($carousel['attachment']))
                $element['photo_id'] = $carousel['attachment'];
            if (isset($carousel['img']))
                $element['photo_id'] = str_replace('photo', '', $this->getMsgAttachmentUploadImage($id, $carousel['img']));
            $template['elements'][] = $element;
        }
        return json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function assembleMsg($id, $var) {
        $this->config_cache = $this->config;

        if ($this->preProcessing($var))
            return null;

        $attachments = [];
        if (!empty($this->config['img']))
            foreach ($this->config['img'] as $img)
                $attachments[] = $this->getMsgAttachmentUploadImage($id, $img[0]);
        if (!empty($this->config['doc']))
            foreach ($this->config['doc'] as $doc)
                $attachments[] = $this->getMsgAttachmentUploadDoc($id, $doc[0], $doc[1]);
        if (!empty($this->config['voice']))
            $attachments[] = $this->getMsgAttachmentUploadVoice($id, $this->config['voice']);
        if (!empty($this->config['attachments']))
            $attachments = array_merge($attachments, $this->config['attachments']);
        if (!empty($this->config['params']['attachment'])) {
            $attachments = array_merge($attachments, $this->config['params']['attachment']);
            unset($this->config['params']['attachment']);
        }
        $attachments = !empty($attachments) ? ['attachment' => join(",", $attachments)] : [];

        if (!empty($this->config['carousel'])) {
            $carousels = $this->config['carousel'];
            foreach ($carousels as $key => $carousel)
                if (!empty($carousel['kbd']))
                    $carousels[$key]['kbd'] = $this->parseKbd([$carousel['kbd']])[0];
            $template = ['template' => $this->generateCarousel($carousels, $id)];
        } else
            $template = [];

        if (isset($this->config['kbd']['kbd']))
            $kbd = ['keyboard' => json_encode([
                'one_time' => $this->config['kbd']['one_time'],
                'buttons' => $this->parseKeyboard($this->parseKbd($this->config['kbd']['kbd'])),
                'inline' => $this->config['kbd']['inline']
            ], JSON_UNESCAPED_UNICODE)];
        else
            $kbd = [];

        $params = $this->config['params'] ?? [];

        if (isset($this->config['forward']['forward'])) {
            $forward = $this->config['forward']['forward'];
            if (empty($forward['peer_id'])) {
                $this->vk->initPeerID($init_peer_id);
                $forward['peer_id'] = $init_peer_id;
            }
            if (empty($forward['message_ids']) && empty($forward['conversation_message_ids'])) {
                $this->vk->initID($msg_id)->initConversationMsgID($convers_msg_id);
                $forward[$msg_id ? 'message_ids' : 'conversation_message_ids'] = $msg_id ?: $convers_msg_id;
            }
            $forward = ['forward' => json_encode($forward)];
        } else
            $forward = $this->config['forward'] ?? [];

        $text = !empty($this->config['text']) ? ['message' => $this->config['text']] : [];
        return $text + $params + $attachments + $kbd + $template + $forward;
    }

    public function sendEdit($peer_id = null, $message_id = null, $cmid = null, $var = null) {
        if(!$peer_id) {
            $this->vk->initPeerID($peer_id);
        }
        if($cmid == null && $message_id == null) {
            $this->vk->initConversationMsgID($cmid);
        }
        $query = $this->assembleMsg($peer_id, $var);

        if (empty($query))
            $result = null;
        else {
            $message_id_key = is_null($message_id) ? 'conversation_message_id' : 'message_id';
            $message_id = $message_id ?? $cmid;
            $result = $this->request('messages.edit', ['peer_id' => $peer_id, $message_id_key => $message_id] + $query);
        }
        $this->postProcessing($peer_id, $message_id ?? $result, $var);
        return $result;
    }

    public function send($id = null, $vk = null, $var = null) {
        if (empty($this->vk) and isset($vk))
            $this->vk = $vk;
        if (empty($this->vk))
            throw new SimpleVkException(0, "Экземпляр SimpleVK не передан");
        if (!empty($this->config['real_id']))
            $id = $this->config['real_id'];
        if (empty($id))
            $this->vk->initPeerID($id);

        $query = $this->assembleMsg($id, $var);
        if (is_null($query)) {
            return null;
        }

        if (empty($query)) {
            $result = null;
        } else {
            $ids = is_array($id) ? join(',', $id) : $id;
            $result = $this->request('messages.send', ['peer_ids' => $ids, 'random_id' => 0] + $query);
            if(!is_array($id)) {
                $result = $result[0]['conversation_message_id'] ?? null;
            } else {
                $result = array_column($result, 'conversation_message_id');
            }
        }

        $this->postProcessing($id, $result, $var);
        return $result;
    }
}