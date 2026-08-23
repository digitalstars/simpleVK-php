<?php

namespace DigitalStars\SimpleVK\V4;

use DigitalStars\SimpleVK\V4\Config\ClientConfig;
use DigitalStars\SimpleVK\V4\Exception\SimpleVkException;
use SensitiveParameter;

/**
 * Загрузка медиафайлов в VK и получение attachment-строк.
 *
 * Пример:
 *   $photo = $uploader->messagePhoto('/path/to/img.jpg');
 *   $msg->attachment($photo)->send();
 */
final class Uploader
{
    private const MAX_FILE_SIZE = 200_000_000; // 200 MB (документы)

    public function __construct(
        private readonly ClientConfig $config,
        private readonly ApiClient $api,
    ) {}

    /**
     * Фото для отправки в личку/беседу.
     */
    public function messagePhoto(string $filePath): string
    {
        $server = $this->api->call('photos.getMessagesUploadServer', $this->peerParams());

        return 'photo'
        . $this->uploadAndSavePhoto((string) $server['upload_url'], $filePath, 'photos.saveMessagesPhoto');
    }

    /**
     * Фото на стену группы/пользователя.
     *
     * @param int|null $ownerId ID владельца стены; null — группа из конфига.
     */
    public function wallPhoto(string $filePath, ?int $ownerId = null): string
    {
        $owner = $ownerId ?? -$this->config->groupId;
        $server = $this->api->call('photos.getWallUploadServer', ['group_id' => abs($this->config->groupId)]);

        return 'photo' . $this->uploadAndSavePhoto((string) $server['upload_url'], $filePath, 'photos.saveWallPhoto', [
            'group_id' => abs($this->config->groupId),
        ]);
    }

    /**
     * Документ (файл) для сообщения.
     */
    public function messageDoc(string $filePath, string $title = ''): string
    {
        $params = $this->peerParams();
        if ($title !== '') {
            $params['title'] = $title;
        }

        $server = $this->api->call('docs.getMessagesUploadServer', $params);
        $saved = $this->uploadDoc((string) $server['upload_url'], $filePath);

        return "doc{$saved['owner_id']}_{$saved['id']}";
    }

    /**
     * Голосовое сообщение.
     */
    public function messageVoice(string $filePath): string
    {
        $server = $this->api->call('docs.getMessagesUploadServer', [...$this->peerParams(), 'type' => 'audio_message']);
        $saved = $this->uploadDoc((string) $server['upload_url'], $filePath);

        return "doc{$saved['owner_id']}_{$saved['id']}";
    }

    /**
     * Гарантирует peer_id для методов, требующих адресат.
     *
     * @return array<string, mixed>
     */
    private function peerParams(): array
    {
        return $this->config->groupId > 0 ? ['peer_id' => 2_000_000_000 + $this->config->groupId] : [];
    }

    /**
     * Загружает фото и сохраняет; возвращает '{owner_id}_{id}'.
     *
     * @param array<string, mixed> $extraSaveParams
     */
    private function uploadAndSavePhoto(
        #[SensitiveParameter]
        string $uploadUrl,
        string $filePath,
        string $saveMethod,
        array $extraSaveParams = [],
    ): string {
        // Ответ upload-сервера содержит поля save-метода (server, photo, hash).
        $uploadResult = $this->uploadFile($uploadUrl, $filePath, 'photo');

        if (!isset($uploadResult['server'])) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                'Uploader: VK не вернул server после загрузки фото',
            );
        }

        $savedList = $this->api->call($saveMethod, [
            ...$extraSaveParams,
            ...array_map(static fn(mixed $v) => is_scalar($v) || $v === null
                ? (string) $v
                : json_encode($v), $uploadResult),
        ]);

        $first = \is_array($savedList) ? $savedList[0] ?? null : null;
        if (!\is_array($first)) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Uploader: {$saveMethod} вернул пустой результат",
            );
        }

        $suffix =
            isset($first['access_key']) && \is_string($first['access_key']) && $first['access_key'] !== ''
                ? '_' . $first['access_key']
                : '';

        return "{$first['owner_id']}_{$first['id']}{$suffix}";
    }

    /**
     * Загружает документ/голосовое; возвращает ответ docs.save.
     *
     * @return array<string, mixed>
     */
    private function uploadDoc(#[SensitiveParameter] string $uploadUrl, string $filePath): array
    {
        $result = $this->uploadFile($uploadUrl, $filePath, 'file');

        $saved = $this->api->call('docs.save', [
            'file' => \is_string($result['file'] ?? null) ? $result['file'] : '',
            'title' => \basename($filePath),
        ]);

        /** @var array{doc?: array{id: numeric-string, owner_id: int}, type?: string} $saved */
        $item = $saved[$saved['type'] ?? 'doc'] ?? null;
        if (!\is_array($item)) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                'Uploader: docs.save вернул пустой результат',
            );
        }

        return ['owner_id' => (int) $item['owner_id'], 'id' => (int) $item['id']];
    }

    /**
     * multipart-загрузка файла на upload_url.
     *
     * @return array<string, mixed>
     */
    private function uploadFile(#[SensitiveParameter] string $uploadUrl, string $filePath, string $fieldName): array
    {
        if (!\is_file($filePath)) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, "Uploader: файл не найден: '{$filePath}'");
        }
        if (\filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Uploader: файл больше лимита VK: '{$filePath}'",
            );
        }

        $ch = \curl_init();
        \curl_setopt_array($ch, [
            \CURLOPT_URL => $uploadUrl,
            \CURLOPT_POST => true,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 120,
            \CURLOPT_POSTFIELDS => [$fieldName => new \CURLFile($filePath)],
        ]);

        try {
            $body = \curl_exec($ch);
            $errno = \curl_errno($ch);

            if ($body === false) {
                throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, "Uploader: сбой загрузки: [{$errno}]");
            }
        } finally {
            \curl_close($ch);
        }

        try {
            $decoded = \json_decode((string) $body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SimpleVkException(
                SimpleVkException::TRANSPORT_ERROR,
                "Uploader: некорректный JSON: {$e->getMessage()}",
            );
        }

        if (!\is_array($decoded)) {
            throw new SimpleVkException(SimpleVkException::TRANSPORT_ERROR, 'Uploader: не-JSON-объект в ответе');
        }

        return $decoded;
    }
}
