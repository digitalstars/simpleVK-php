<?php

namespace DigitalStars\SimpleVK;

/**
 * Базовый конструктор сообщений/постов: текст, вложения, цепочки функций.
 */
class BaseConstructor {
    protected array $config;
    protected array $config_cache;
    /** @var SimpleVK|null */
    protected $vk = null;

    public function __construct($vk = null, &$cfg = null) {
        if (!isset($cfg))
            $this->config = [];
        else
            $this->config = &$cfg;
        $this->vk = $vk;
    }

    /**
     * Магическое добавление функций в цепочку: a_<func>() — после отправки, b_<func>() — до.
     */
    public function __call($name, $arguments) {
        $prefix = substr($name, 0, 2);
        $func = substr($name, 2);
        if ($prefix == 'a_')
            $prefix = 'func_after_chain';
        else if ($prefix == 'b_')
            $prefix = 'func_before_chain';
        else
            throw new SimpleVkException(0, 'Неверно задан префикс функции');
        if (is_callable($func))
            $this->config[$prefix][] = ['f' => $func, 'args' => $arguments];
        else
            throw new SimpleVkException(0, 'Функция ' . $func . ' недоступна');
        return $this;
    }

    public function a_sleep(int|string|float $time): static {
        $this->config['func_after_chain'][] = ['f' => 'sleep', 'args' => [$time]];
        return $this;
    }

    public function b_sleep(int|string|float $time): static {
        $this->config['func_before_chain'][] = ['f' => 'sleep', 'args' => [$time]];
        return $this;
    }

    public function clearChainAfter(): static {
        $this->config['func_after_chain'] = [];
        return $this;
    }

    public function getChainAfter(): ?array {
        return $this->config['func_after_chain'] ?? null;
    }

    public function clearChainBefore(): static {
        $this->config['func_before_chain'] = [];
        return $this;
    }

    public function getChainBefore(): ?array {
        return $this->config['func_before_chain'] ?? null;
    }

    public function text(string $text): static {
        $this->config['text'] = $text;
        return $this;
    }

    /** Задаёт изображения (пути/URL, в т.ч. вложенными массивами). */
    public function img(mixed ...$imgs): static {
        $this->config['img'] = $this->imgParse($imgs);
        return $this;
    }

    /** Задаёт документы (строки или ['path' => ..., 'title' => ...]). */
    public function doc(mixed ...$docs): static {
        $this->config['doc'] = $this->docParse($docs);
        return $this;
    }

    /** Добавляет документы к уже заданным. */
    public function addDoc(mixed ...$docs): static {
        if (empty($this->config['doc']))
            $this->config['doc'] = $this->docParse($docs);
        else
            $this->config['doc'] = array_merge($this->config['doc'], $this->docParse($docs));
        return $this;
    }

    /** Добавляет изображения к уже заданным. */
    public function addImg(mixed ...$imgs): static {
        if (empty($this->config['img']))
            $this->config['img'] = $this->imgParse($imgs);
        else
            $this->config['img'] = array_merge($this->config['img'], $this->imgParse($imgs));
        return $this;
    }

    private function removeEx(array &$extends, array $removed): static {
        if (empty($removed)) {
            return $this;
        }
        foreach ($removed as $remove_item)
            foreach ($extends as $index => $extend)
                if (is_array($extend)) {
                    if ($extend[0] == $remove_item[0] and (empty($extend[1]) or $extend[1] == $remove_item[1])) {
                        unset($extends[$index]);
                        break;
                    }
                } else {
                    if ($extend == $remove_item) {
                        unset($extends[$index]);
                        break;
                    }
                }
        return $this;
    }

    public function removeDoc(mixed ...$docs): static {
        return $this->removeEx($this->config['doc'], $this->docParse($docs));
    }

    public function removeImg(mixed ...$imgs): static {
        return $this->removeEx($this->config['img'], $this->imgParse($imgs));
    }

    public function removeAttachment(mixed ...$attachs): static {
        return $this->removeEx($this->config['attachments'], $this->attachmentParse($attachs));
    }

    /** Произвольные дополнительные параметры метода VK API. */
    public function params(array $params): static {
        $this->config['params'] = $params;
        return $this;
    }

    /** Задаёт готовые вложения (например photo123_456), перезаписывая предыдущие. */
    public function attachment(string|array ...$attachs): static {
        $this->config['attachments'] = $this->attachmentParse($attachs);
        return $this;
    }

    /** Добавляет готовые вложения к уже заданным. */
    public function addAttachment(string|array ...$attachs): static {
        if (empty($this->config['attachments']))
            $this->config['attachments'] = $this->attachmentParse($attachs);
        else
            $this->config['attachments'] = array_merge($this->config['attachments'], $this->attachmentParse($attachs));
        return $this;
    }

    /**
     * Замыкание, вызываемое перед отправкой: fn(Message $msg, mixed $var): bool|null.
     * Если возвращает truthy — отправка прерывается.
     */
    public function func(?callable $func = null): static {
        $this->config['func'] = $func;
        return $this;
    }

    /** Замыкание, вызываемое после отправки: fn(mixed $result, mixed $var): bool|null. */
    public function afterFunc(?callable $func = null): static {
        $this->config['func_after'] = $func;
        return $this;
    }

    public function getFunc(): ?callable {
        return $this->config['func'] ?? null;
    }

    public function getAfterFunc(): ?callable {
        return $this->config['func_after'] ?? null;
    }

    /** Переопределяет итоговый peer_id/owner_id при отправке. */
    public function finalSendID(int|string $id): static {
        $this->config['real_id'] = $id;
        return $this;
    }

    /** @return int|string|null */
    public function getFinalSendID(): int|string|null {
        return $this->config['real_id'] ?? null;
    }

    public function getDoc(): ?array {
        return $this->config['doc'] ?? null;
    }

    public function getImg(): array {
        return $this->config['img'] ?? [];
    }

    public function getText(): string {
        return $this->config['text'] ?? '';
    }

    public function getParams(): array {
        return $this->config['params'] ?? [];
    }

    public function getAttachment(): array {
        return $this->config['attachments'] ?? [];
    }

    public function dump(): array {
        return $this->config;
    }

    protected function request(string $method, array $params = []) {
        return $this->vk?->request($method, $params);
    }

    protected function null(): true {
        $this->config = $this->config_cache;
        return true;
    }

    protected function preProcessing($var): bool {
        if (isset($this->config['func']) and is_callable($this->config['func']))
            if ($this->config['func']($this, $var))
                return $this->null();
        if (!empty($this->config['func_before_chain'])) {
            $is_isset_bot = isset($this->bot);
            foreach ($this->config['func_before_chain'] as $func) {
                if ($func['f'] == 'run') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(0, "->run() можно использовать только если Message создан через Bot");
                    $this->bot->run($func['args']);
                } else
                    call_user_func_array($func['f'], $func['args']);
                if ($is_isset_bot && $this->bot->getStatus())
                    return $this->null();
            }
        }
        if (!empty($this->config['event'])) {
            if (!isset($this->bot))
                throw new SimpleVkException(0, "Методы ->event...() можно использовать только если Message создан через Bot");
            if ($this->config['event']['type'] == 0)
                $this->vk->eventAnswerSnackbar($this->config['event']['text']);
            else if ($this->config['event']['type'] == 1)
                $this->vk->eventAnswerOpenLink($this->config['event']['url']);
            else if ($this->config['event']['type'] == 2)
                $this->vk->eventAnswerOpenApp($this->config['event']['app_id'], $this->config['event']['owner_id'], $this->config['event']['hash']);
        }
        return false;
    }

    protected function postProcessing($id, $result, $var): bool {
        if (isset($this->config['func_after']) and is_callable($this->config['func_after']))
            if ($this->config['func_after']($result, $var))
                return $this->null();
        if (!empty($this->config['func_after_chain'])) {
            $is_isset_bot = isset($this->bot);
            foreach ($this->config['func_after_chain'] as $func) {
                if ($func['f'] == 'run') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(0, "->run() можно использовать только если Message создан через Bot");
                    $this->bot->run($func['args'], $id);
                } else if ($func['f'] == 'edit') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(0, "->edit() можно использовать только если Message создан через Bot");
                    $this->bot->editRun($func['args'], $id, $result);
                } else
                    call_user_func_array($func['f'], $func['args']);
                if ($is_isset_bot && $this->bot->getStatus())
                    return $this->null();
            }
        }
        return $this->null();
    }

    /**
     * @param array|string $imgs
     * @return array
     */
    private function imgParse(array|string $imgs): array {
        if (is_string($imgs))
            return [[$imgs]];
        $result = [];
        foreach ($imgs as $img)
            $result = array_merge($result, $this->imgParse($img));
        return $result;
    }

    /**
     * @param array|string $docs
     * @return array
     */
    private function docParse(array|string $docs): array {
        if (is_string($docs))
            return [[$docs, null]];
        else if (isset($docs['path']))
            return [[$docs['path'], $docs['title'] ?? null]];
        $result = [];
        foreach ($docs as $doc)
            $result = array_merge($result, $this->docParse($doc));
        return $result;
    }

    private function attachmentParse(array $attachs): array {
        $result = [];
        foreach ($attachs as $attach)
            if (is_string($attach))
                $result[] = $attach;
            else
                $result = array_merge($result, $this->attachmentParse($attach));
        return $result;
    }
}
