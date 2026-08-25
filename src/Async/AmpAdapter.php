<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Async;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Bot;
use DigitalStars\SimpleVK\Config\ClientConfig;
use Throwable;

use function Amp\async;

/**
 * Асинхронный транспорт и LongPoll-цикл поверх Amphp (Revolt event loop).
 *
 * Тот же Bot с теми же обработчиками: меняется только транспорт конфига.
 *
 * Пример:
 *   $config = ClientConfig::fromEnv()->withTransport(AmpAdapter::transport());
 *   $bot = Bot::create($config)->onMessage(...);
 *   AmpAdapter::run($bot);   // неблокирующий LongPoll на event loop
 */
final class AmpAdapter
{
    private static ?HttpClient $httpClient = null;

    private const int LP_MODE = 2 | 8 | 64 | 128 | 256;
    private const int LP_VERSION = 3;

    public static function transport(?HttpClient $client = null): AmpTransport
    {
        return new AmpTransport($client ?? self::httpClient());
    }

    /**
     * Запускает бесконечный асинхронный LongPoll-цикл.
     *
     * Блокирует вызывающий поток только в смысле «до остановки цикла»,
     * но не блокирует event loop: другие корутины продолжают работать.
     */
    public static function run(Bot $bot, ?ApiClient $api = null): void
    {
        $config = $bot->config;
        $api ??= new ApiClient($config, self::transport(), $config->logger);

        async(static function () use ($bot, $api, $config): void {
            $server = $api->call('groups.getLongPollServer', ['group_id' => $config->groupId]);
            $key = (string) $server['key'];
            $base = \rtrim((string) $server['server'], '/');
            $ts = (int) $server['ts'];

            while (true) {
                try {
                    $data = self::poll($base, $key, $ts);

                    $failed = (int) ($data['failed'] ?? 0);
                    if ($failed === 1 && isset($data['ts'])) {
                        $ts = (int) $data['ts'];

                        continue;
                    }
                    if ($failed !== 0) {
                        $fresh = $api->call('groups.getLongPollServer', ['group_id' => $config->groupId]);
                        $key = (string) $fresh['key'];
                        $base = \rtrim((string) $fresh['server'], '/');
                        $ts = (int) $fresh['ts'];

                        continue;
                    }

                    $ts = (int) ($data['ts'] ?? $ts);

                    foreach (\is_array($data['updates'] ?? null) ? $data['updates'] : [] as $update) {
                        $bot->dispatch($update);
                    }
                } catch (Throwable $e) {
                    $config->logger->warning('Amp longpoll error, retrying', ['error' => $e->getMessage()]);
                    \Amp\delay(1000);
                }
            }
        })->await();
    }

    /**
     * @return array<string, mixed>
     */
    private static function poll(string $base, string $key, int $ts): array
    {
        $url = \sprintf(
            '%s?act=a_check&key=%s&ts=%d&wait=25&mode=%d&version=%d',
            $base,
            \urlencode($key),
            $ts,
            self::LP_MODE,
            self::LP_VERSION,
        );

        $response = self::httpClient()->request(new Request($url, 'GET'));
        $body = $response->getBody()->buffer();

        return \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    }

    public static function httpClient(): HttpClient
    {
        return self::$httpClient ??= new HttpClientBuilder()->build();
    }

    public static function setHttpClient(HttpClient $client): void
    {
        self::$httpClient = $client;
    }
}
