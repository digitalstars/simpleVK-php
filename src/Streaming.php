<?php

namespace DigitalStars\SimpleVK;

/**
 * Клиент Streaming API VK: правила потока и чтение событий через websocket-сокет.
 */
class Streaming
{
    private string $rules_url;
    private string $stream_url;
    private string $token;
    private string $version;
    /** @var resource */
    private $socket;
    private string $stream_query;

    public function __construct(string $token, string $version)
    {
        if (!function_exists('curl_init')) {
            exit('Для работы streaming небоходим curl. Прекращение работы');
        }

        $this->token = $token;
        $this->version = $version;
        $this->getStreamingServer();
        $this->connect();
    }

    private function getStreamingServer(): void
    {
        $response = $this->request(
            "https://api.vk.com/method/streaming.getServerUrl?v={$this->version}&access_token={$this->token}",
            'GET',
        )['response'];
        $this->rules_url = "https://$response[endpoint]/rules?key=$response[key]";
        $this->stream_url = "ssl://$response[endpoint]:443";
        $this->stream_query = "/stream?key=$response[key]";
    }

    /**
     * @return array<array> Текущий список правил потока.
     */
    public function getRules(): array
    {
        return $this->request($this->rules_url, 'GET')['rules'];
    }

    /** Добавляет правило фильтрации потока. @return array Ответ API. */
    public function addRule(string $value, string $tag): array
    {
        $json = ['rule' => ['value' => $value, 'tag' => $tag]];
        return $this->request($this->rules_url, 'POST', $json);
    }

    /** Удаляет правило по тегу. @return array Ответ API. */
    public function deleteRule(string $tag): array
    {
        $json = ['tag' => $tag];
        return $this->request($this->rules_url, 'DELETE', $json);
    }

    /** Удаляет все правила потока. */
    public function deleteAllRules(): true
    {
        foreach ($this->getRules() as $rule) {
            $this->deleteRule($rule['tag']);
        }
        return true;
    }

    /**
     * Бесконечный цикл чтения событий потока.
     *
     * @param callable $callback fn(array $event): void — декодированное событие
     *        с обработанным полем text.
     */
    public function listen(callable $callback): void
    {
        while (true) {
            $data = $this->readBytes(2);
            if ($data === '') {
                continue; // соединение закрылось/нет данных — не крутим CPU
            }
            $opcode = ord($data[0]) & 31;
            if ($opcode === 9) { // ping
                $this->pong();
            } else {
                $event_data = $this->getPayload();
                $event_data = json_decode($event_data, true, 512, JSON_THROW_ON_ERROR);
                $event_data['event']['text'] = $this->processData((string) $event_data['event']['text']);
                // Фикс: раньше в колбэк уходили 2 байта заголовка кадра вместо события
                $callback($event_data);
            }
        }
    }

    private function processData(string $data): string
    {
        $data = str_replace("\u003cbr\u003e", "\n", $data);
        return html_entity_decode($data, ENT_QUOTES, 'UTF-8');
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return $data;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /** Отвечает pong-кадром на ping сервера. */
    private function pong(): void
    {
        $payload = 'PONG';
        $payload_length = strlen($payload);

        // Формируем заголовок кадра (2 байта)
        $frame_head = chr(0b10001010) . chr($payload_length | 0b10000000);

        $mask = random_bytes(4);
        $frame_head .= $mask;

        // Формируем полезную нагрузку с применением маски
        $masked_payload = '';
        for ($i = 0; $i < $payload_length; $i++) {
            $masked_payload .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket, $frame_head . $masked_payload);
    }

    /**
     * Читает payload текущего кадра.
     *
     * ИСТОРИЧЕСКОЕ ПОВЕДЕНИЕ СОХРАНЕНО: длина берётся из следующих 2 байт
     * (extended-length формат). Кадры VK Streaming укладываются в этот формат.
     */
    private function getPayload(): string
    {
        $data = $this->readBytes(2);
        $payload_length = bindec(implode('', array_map(static fn($char) => sprintf(
            '%08b',
            ord($char),
        ), str_split($data))));

        return $this->readBytes($payload_length);
    }

    private function request(string $url, string $type, array $json = [])
    {
        return $this->request_core($url, $type, $json);
    }

    /**
     * @return mixed Раскодированный ответ Streaming API.
     * @throws SimpleVkException Пустой ответ или код 400.
     */
    private function request_core(string $url, string $type, array $json)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $type,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
        ]);

        if (!empty($json)) {
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($json),
            ]);
        }

        try {
            $result = json_decode((string) curl_exec($ch), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new SimpleVkException(77777, 'Вк вернул пустой или невалидный ответ');
        } finally {
            unset($ch);
        }

        if (empty($result)) {
            throw new SimpleVkException(77777, 'Вк вернул пустой ответ');
        }
        if (($result['code'] ?? null) == 400) {
            throw new SimpleVkException((int) $result['code'], json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return $result;
    }

    private function connect(): void
    {
        $context = stream_context_create();
        $this->socket = @stream_socket_client(
            $this->stream_url,
            $errno,
            $errstr,
            1000,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!$this->socket) {
            throw new SimpleVkException(77781, "Не удалось подключиться к Streaming API: $errstr ($errno)");
        }
        $key = $this->generateWebSocketKey();
        $header =
            "GET {$this->stream_query} HTTP/1.1\r\n"
            . "Host: streaming.vk.com:443\r\n"
            . "User-Agent: websocket-client-php\r\n"
            . "Connection: Upgrade\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($this->socket, $header);
        stream_get_line($this->socket, 1024, "\r\n\r\n");
    }

    private function generateWebSocketKey(): string
    {
        return base64_encode(random_bytes(16));
    }
}
