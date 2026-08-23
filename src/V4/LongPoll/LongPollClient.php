<?php

namespace DigitalStars\SimpleVK\V4\LongPoll;

use DigitalStars\SimpleVK\V4\ApiClient;
use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Event\Update;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;

/**
 * Получатель событий Long Poll API.
 *
 * Сам управляет сервером: groups.getLongPollServer, повторное обновление ключа
 * при code 2/3, ожидание при ts-таймаутах и mode-параметрах.
 */
class LongPollClient
{
    private const int MODE_ATTACHMENTS = 2; // возвращать вложения
    private const int MODE_EXTENDED_EVENTS = 8; // расширенный набор событий
    private const int MODE_EXTRA_DATA = 64; // payload в сообщениях
    private const int MODE_MESSAGE_PAYLOAD = 128; // payload кнопок
    private const int MODE_EVENT_ID = 256; // event_id в событиях
    private const int VERSION = 3;

    /** @var array{key: string, server: string, ts: int}|null */
    private ?array $server = null;

    public function __construct(
        private readonly ClientConfig $config,
        private readonly ApiClient $api,
    ) {
        if ($this->config->groupId <= 0) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                'Для LongPoll нужен groupId (ClientConfig::fromEnv() или ->with(...))',
            );
        }
    }

    /**
     * Блокирующе ждёт следующую пачку событий.
     *
     * @return list<Update>
     */
    public function wait(): array
    {
        $this->server ??= $this->initServer();

        try {
            $response = $this->poll();
        } catch (LongPollReset) {
            $this->server = null; // сервер/ключ устарели — переинициализация на следующем wait()

            return [];
        }

        if ($this->server !== null) {
            $this->server['ts'] = (int) ($response['ts'] ?? 0);
        }

        /** @var list<array<string, mixed>> $rawUpdates */
        $rawUpdates = \is_array($response['updates'] ?? null) ? $response['updates'] : [];

        return \array_map(Update::fromLongPoll(...), $rawUpdates);
    }

    /**
     * Точка HTTP-ввода для тестов (переопределяется в наследниках).
     *
     * @return string|false
     */
    protected function httpGet(string $url): string|false
    {
        return \file_get_contents($url, context: \stream_context_create(['http' => ['timeout' => 35]]));
    }

    /**
     * Один HTTP-запрос к LongPoll-серверу с обработкой failed-кодов.
     *
     * @return array<string, mixed>
     */
    private function poll(): array
    {
        $server = $this->server;
        if ($server === null) {
            return [];
        }
        $url = \sprintf(
            '%s?act=a_check&key=%s&ts=%d&wait=25&mode=%d&version=%d',
            $server['server'],
            \urlencode($server['key']),
            $server['ts'],
            self::MODE_ATTACHMENTS
            | self::MODE_EXTENDED_EVENTS
            | self::MODE_EXTRA_DATA
            | self::MODE_MESSAGE_PAYLOAD
            | self::MODE_EVENT_ID,
            self::VERSION,
        );

        $raw = $this->httpGet($url);

        if ($raw === false) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'LongPoll: таймаут или сбой сети');
        }

        try {
            $data = \json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "LongPoll: некорректный JSON: {$e->getMessage()}",
            );
        }

        return match ((int) ($data['failed'] ?? 0)) {
            0 => $data,
            1 => throw new LongPollReset(ts: isset($data['ts']) ? (int) $data['ts'] : null), // история устарела, ts новый
            2, 3 => throw new LongPollReset(), // истёк ключ / потерян сервер
            default => throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "LongPoll: unknown failed={$data['failed']}",
            ),
        };
    }

    /**
     * @return array{key: string, server: string, ts: int}
     */
    private function initServer(): array
    {
        $response = $this->api->call('groups.getLongPollServer', [
            'group_id' => $this->config->groupId,
        ]);

        return [
            'key' => (string) $response['key'],
            'server' => (string) $response['server'],
            'ts' => (int) $response['ts'],
        ];
    }
}
