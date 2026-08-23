<?php

namespace DigitalStars\SimpleVK\V4\Streaming;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;

/**
 * Streaming API: подписка на поток публичных данных VK (слова/гео).
 *
 * Протокол поверх websocket: кадры с заголовками длины; правила задаются
 * через Rules API, события читаются непрерывно.
 */
final class StreamingClient
{
    private const STREAMING_URL = 'wss://streaming.vk.com';

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly ApiClient $api,
    ) {}

    /**
     * Добавляет правило потока.
     */
    public function addRule(string $value, ?string $tag = null): void
    {
        $this->api->call('streaming.execute', [
            'code' => \sprintf(
                'API.streaming.addRules({"rules":[{"value":%s,"tag":%s}]})',
                \json_encode($value, \JSON_UNESCAPED_UNICODE),
                \json_encode($tag ?? $value, \JSON_UNESCAPED_UNICODE),
            ),
        ]);
    }

    /**
     * Блокирующий цикл чтения событий потока; $callback вызывается на каждое.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function listen(callable $callback): void
    {
        $this->connect();

        while (\is_resource($this->socket)) {
            $frame = $this->readFrame();

            if ($frame === null) {
                break; // соединение закрыто сервером
            }

            try {
                $event = \json_decode($frame, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (\is_array($event) && isset($event['event'])) {
                $callback($event['event']);
            }
        }
    }

    private function connect(): void
    {
        $endpoint = $this->getEndpoint();
        // wss://host/path → ssl://host:443
        $parts = \parse_url($endpoint);
        $host = ($parts['host'] ?? 'streaming.vk.com') . ':443';
        $path = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');

        $socket = \stream_socket_client("ssl://{$host}", $errno, $errstr, 10);
        if ($socket === false) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Streaming connect failed: [{$errno}] {$errstr}",
            );
        }
        \stream_set_timeout($socket, 30);

        \fwrite(
            $socket,
            "GET {$path} HTTP/1.1\r\nHost: {$parts['host']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: "
            . \base64_encode(\random_bytes(16))
            . "\r\nSec-WebSocket-Version: 13\r\n\r\n",
        );

        $handshake = '';
        while (!\str_contains($handshake, "\r\n\r\n")) {
            $chunk = \fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'Streaming handshake failed');
            }
            $handshake .= $chunk;
        }

        $this->socket = $socket;
    }

    /**
     * Читает один websocket-кадр (текстовый), возвращает payload или null при закрытии.
     */
    private function readFrame(): ?string
    {
        $header = $this->readBytes(2);

        if ($header === null) {
            return null;
        }

        $length = \ord($header[1]) & 0x7F;

        if ($length === 126) {
            $extended = $this->readBytes(2);
            if ($extended === null) {
                return null;
            }
            $length = \unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended64 = $this->readBytes(8);
            if ($extended64 === null) {
                return null;
            }
            $length = \unpack('J', $extended64)[1];
        }

        return $this->readBytes($length);
    }

    /**
     * @return string|null null — соединение закрыто.
     */
    private function readBytes(int $count): ?string
    {
        if (!\is_resource($this->socket)) {
            return null;
        }

        $data = '';
        while (\strlen($data) < $count) {
            $chunk = \fread($this->socket, $count - \strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function getEndpoint(): string
    {
        $response = $this->api->call('streaming.getServerUrl');

        return (string) ($response['endpoint'] ?? self::STREAMING_URL);
    }

    public function __destruct()
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
    }
}
