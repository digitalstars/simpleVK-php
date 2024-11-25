<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\SimpleVK as vk;

class Streaming {
    private string $rules_url;
    private string $stream_url;
    private string $token;
    private string $version;
    private $socket;
    private string $stream_query;

    public function __construct(string $token, string $version) {
        if (!function_exists('curl_init')) {
            exit('Для работы streaming небоходим curl. Прекращение работы');
        }

        $this->token = $token;
        $this->version = $version;
        $this->getStreamingServer();
        $this->connect();
    }

    private function getStreamingServer(): void {
        $response = $this->request("https://api.vk.com/method/streaming.getServerUrl?v={$this->version}&access_token={$this->token}", 'GET')['response'];
        $this->rules_url = "https://$response[endpoint]/rules?key=$response[key]";
        $this->stream_url = "ssl://$response[endpoint]:443";
        $this->stream_query = "/stream?key=$response[key]";
    }

    public function getRules(): array {
        return $this->request($this->rules_url, 'GET')['rules'];
    }

    public function addRule(string $value, string $tag): array {
        $json = ['rule' => ['value' => $value, 'tag' => $tag]];
        return $this->request($this->rules_url, 'POST', $json);
    }

    public function deleteRule(string $tag): array {
        $json = ['tag' => $tag];
        return $this->request($this->rules_url, 'DELETE', $json);
    }

    public function deleteAllRules(): bool {
        foreach ($this->getRules() as $rule) {
            $this->deleteRule($rule['tag']);
        }
        return true;
    }

    public function listen(callable $callback): void {
        while (true) {
            $data = $this->readBytes(2);
            $opcode = ord($data[0]) & 31;
            if ($opcode === 9) { // ping
                $this->pong();
            } else {
                $event_data = $this->getPayload();
                $event_data = json_decode($event_data, true, 512, JSON_THROW_ON_ERROR);
                $event_data['event']['text'] = $this->processData($event_data['event']['text']);
                $callback($data);
            }
        }
    }

    private function processData(string $data): string {
        $data = str_replace("\u003cbr\u003e", "\n", $data);
        return html_entity_decode($data, ENT_QUOTES, 'UTF-8');
    }

    private function readBytes(int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $data .= fread($this->socket, $length - strlen($data));
        }
        return $data;
    }

    private function pong() {
        $payload = 'PONG';
        $payload_length = strlen($payload);

        // Формируем заголовок кадра (2 байта)
        // 138 . payloadLength + 128
        $frame_head = chr(0b10001010) . chr($payload_length | 0b10000000);

        $mask = random_bytes(4);
        $frame_head .= $mask;

        // Формируем полезную нагрузку с применением маски
        $masked_payload = '';
        for ($i = 0; $i < $payload_length; $i++) {
            $masked_payload .= $payload[$i] ^ $mask[$i % 4];
        }

        $frame = $frame_head . $masked_payload;

        fwrite($this->socket, $frame);
    }

    private function getPayload() {
        $data = $this->readBytes(2);
        $payload_length = bindec(implode('', array_map(static function ($char) {
            return sprintf('%08b', ord($char));
        }, str_split($data))));

        return $this->readBytes($payload_length);
    }

    private function request($url, $type, $json = []) {
        return $this->request_core($url, $type, $json);
    }

    private function request_core($url, $type, $json) {
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
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_POSTFIELDS => json_encode($json),
            ]);
        }

        $result = json_decode(curl_exec($ch), true, 512, JSON_THROW_ON_ERROR);
        curl_close($ch);

        if (empty($result)) {
            throw new SimpleVkException(77777, 'Вк вернул пустой ответ');
        }
        if ($result['code'] == 400) {
            throw new SimpleVkException($result['code'], json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    private function connect() {
        $context = stream_context_create();
        $this->socket = @stream_socket_client($this->stream_url, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $context);
        $key = $this->generateWebSocketKey();
        $header = "GET {$this->stream_query} HTTP/1.1\r\n" .
            "Host: streaming.vk.com:443\r\n" .
            "User-Agent: websocket-client-php\r\n" .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            "Sec-WebSocket-Key: $key\r\n" .
            "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($this->socket, $header);
        stream_get_line($this->socket, 1024, "\r\n\r\n");
    }

    private function generateWebSocketKey() {
        return base64_encode(random_bytes(16));
    }
}