<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

class BaseConstructor
{
    protected $config;
    protected $config_cache;
    /** @var SimpleVK */
    protected $vk = null;

    public function __construct($vk = null, &$cfg = null)
    {
        if (!isset($cfg))
            $this->config = [];
        else
            $this->config = &$cfg;
        $this->vk = $vk;
    }

    public function __call($name, $arguments)
    {
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

    public function a_sleep($time)
    {
        $this->config['func_after_chain'][] = ['f' => 'sleep', 'args' => [$time]];
        return $this;
    }

    public function b_sleep($time)
    {
        $this->config['func_before_chain'][] = ['f' => 'sleep', 'args' => [$time]];
        return $this;
    }

    public function clearChainAfter()
    {
        $this->config['func_after_chain'] = [];
        return $this;
    }

    public function getChainAfter()
    {
        return $this->config['func_after_chain'] ?? null;
    }

    public function clearChainBefore()
    {
        $this->config['func_before_chain'] = [];
        return $this;
    }

    public function getChainBefore()
    {
        return $this->config['func_before_chain'] ?? null;
    }

    public function text($text)
    {
        $this->config['text'] = $text;
        return $this;
    }

    public function img()
    {
        $this->config['img'] = $this->imgParse(func_get_args());
        return $this;
    }

    public function doc()
    {
        $this->config['doc'] = $this->docParse(func_get_args());
        return $this;
    }

    public function addDoc()
    {
        if (empty($this->config['doc']))
            $this->config['doc'] = $this->docParse(func_get_args());
        else
            $this->config['doc'] = array_merge($this->config['doc'], $this->docParse(func_get_args()));
        return $this;
    }

    public function addImg()
    {
        if (empty($this->config['img']))
            $this->config['img'] = $this->imgParse(func_get_args());
        else
            $this->config['img'] = array_merge($this->config['img'], $this->imgParse(func_get_args()));
        return $this;
    }

    private function removeEx(&$extends, $removed)
    {
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

    public function removeDoc()
    {
        return $this->removeEx($this->config['doc'], $this->docParse(func_get_args()));
    }

    public function removeImg()
    {
        return $this->removeEx($this->config['img'], $this->imgParse(func_get_args()));
    }

    public function removeAttachment()
    {
        return $this->removeEx($this->config['attachments'], $this->attachmentParse(func_get_args()));
    }

    public function params($params)
    {
        $this->config['params'] = $params;
        return $this;
    }

    public function attachment()
    {
        $this->config['attachments'] = $this->attachmentParse(func_get_args());
        return $this;
    }

    public function addAttachment()
    {
        if (empty($this->config['attachments']))
            $this->config['attachments'] = $this->attachmentParse(func_get_args());
        else
            $this->config['attachments'] = array_merge(
                $this->config['attachments'],
                $this->attachmentParse(func_get_args()),
            );
        return $this;
    }

    public function func($func = null)
    {
        $this->config['func'] = $func;
        return $this;
    }

    public function afterFunc($func = null)
    {
        $this->config['func_after'] = $func;
        return $this;
    }

    public function getFunc()
    {
        return $this->config['func'] ?? null;
    }

    public function getAfterFunc()
    {
        return $this->config['func_after'] ?? null;
    }

    public function finalSendID($id)
    {
        $this->config['real_id'] = $id;
        return $this;
    }

    public function getFinalSendID()
    {
        return $this->config['real_id'] ?? null;
    }

    public function getDoc()
    {
        return $this->config['doc'] ?? null;
    }

    public function getImg()
    {
        return $this->config['img'] ?? [];
    }

    public function getText()
    {
        return $this->config['text'] ?? '';
    }

    public function getParams()
    {
        return $this->config['params'] ?? [];
    }

    public function getAttachment()
    {
        return $this->config['attachments'] ?? [];
    }

    public function dump()
    {
        return $this->config;
    }

    protected function request($method, $params = [])
    {
        return $this->vk->request($method, $params);
    }

    protected function null()
    {
        $this->config = $this->config_cache;
        return true;
    }

    protected function preProcessing($var)
    {
        if (isset($this->config['func']) and is_callable($this->config['func']))
            if ($this->config['func']($this, $var))
                return $this->null();
        if (!empty($this->config['func_before_chain'])) {
            $is_isset_bot = isset($this->bot);
            foreach ($this->config['func_before_chain'] as $func) {
                if ($func['f'] == 'run') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(
                            0,
                            '->run() можно использовать только если Message создан через Bot',
                        );
                    $this->bot->run($func['args']);
                } else
                    call_user_func_array($func['f'], $func['args']);
                if ($is_isset_bot && $this->bot->getStatus())
                    return $this->null();
            }
        }
        if (!empty($this->config['event'])) {
            if (!isset($this->bot))
                throw new SimpleVkException(
                    0,
                    'Методы ->event...() можно использовать только если Message создан через Bot',
                );
            if ($this->config['event']['type'] == 0)
                $this->vk->eventAnswerSnackbar($this->config['event']['text']);
            else if ($this->config['event']['type'] == 1)
                $this->vk->eventAnswerOpenLink($this->config['event']['url']);
            else if ($this->config['event']['type'] == 2)
                $this->vk->eventAnswerOpenApp(
                    $this->config['event']['app_id'],
                    $this->config['event']['owner_id'],
                    $this->config['event']['hash'],
                );
        }
        return false;
    }

    protected function postProcessing($id, $result, $var)
    {
        if (isset($this->config['func_after']) and is_callable($this->config['func_after']))
            if ($this->config['func_after']($result, $var))
                return $this->null();
        if (!empty($this->config['func_after_chain'])) {
            $is_isset_bot = isset($this->bot);
            foreach ($this->config['func_after_chain'] as $func) {
                if ($func['f'] == 'run') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(
                            0,
                            '->run() можно использовать только если Message создан через Bot',
                        );
                    $this->bot->run($func['args'], $id);
                } else if ($func['f'] == 'edit') {
                    if (!$is_isset_bot)
                        throw new SimpleVkException(
                            0,
                            '->edit() можно использовать только если Message создан через Bot',
                        );
                    $this->bot->editRun($func['args'], $id, $result);
                } else
                    call_user_func_array($func['f'], $func['args']);
                if ($is_isset_bot && $this->bot->getStatus())
                    return $this->null();
            }
        }
        return $this->null();
    }

    private function imgParse($imgs)
    {
        if (is_string($imgs))
            return [[$imgs]];
        $result = [];
        foreach ($imgs as $img)
            $result = array_merge($result, $this->imgParse($img));
        return $result;
    }

    private function docParse($docs)
    {
        if (is_string($docs))
            return [[$docs, null]];
        else if (isset($docs['path']))
            return [[$docs['path'], $docs['title'] ?? null]];
        $result = [];
        foreach ($docs as $doc)
            $result = array_merge($result, $this->docParse($doc));
        return $result;
    }

    private function attachmentParse($attachs)
    {
        $result = [];
        foreach ($attachs as $attach)
            if (is_string($attach))
                $result[] = $attach;
            else
                $result = array_merge($result, $this->attachmentParse($attach));
        return $result;
    }
}
