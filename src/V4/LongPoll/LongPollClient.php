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
final class LongPollClient
{
    private const MODE_ATTACHMENTS = 2; // возвращать вложения
    private const MODE_EXTENDED_EVENTS = 8; // расширенный набор событий
    private const MODE_EXTRA_DATA = 64; // payload в сообщениях
    private const MODE_MESSAGE_PAYLOAD = 128; // payload кнопок
    private const MODE_EVENT_ID = 256; // event_id в событиях
    private const VERSION = 3;

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

        $this->server['ts'] = (int) ($response['ts'] ?? 0);

        return \array_map(
            static fn(array $raw): Update => Update::fromLongPoll($raw),
            \is_array($response['updates'] ?? null) ? $response['updates'] : [],
        );
    }

    /**
     * Один HTTP-запрос к LongPoll-серверу с обработкой failed-кодов.
     *
     * @return array<string, mixed>
     */
    private function poll(): array
    {
        $server = $this->server;
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

        $raw = \file_get_contents($url, context: \stream_context_create(['http' => ['timeout' => 35]]));

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

/**
 * Внутреннее исключение перезапуска LongPoll-сессии.
 */
final class LongPollReset extends \RuntimeException
{
    public function __construct(
        public readonly ?int $ts = null,
    ) {
        parent::__construct('LongPoll session reset');
    }
}
