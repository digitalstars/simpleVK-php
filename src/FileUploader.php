<?php

namespace DigitalStars\SimpleVK;

use CURLFile;

require_once('config_simplevk.php');

trait FileUploader {
    use Request;

    /* Как работает загрузка картинок от чьего-то лица:
       — Если ты вызвал получение сервера для загрузки с peer_id > 2e9 (беседы), то загрузит в сервер создателя беседы(пользователь или сообщество),
          и именно этот случай багует в беседах чужих сообществ. Потому что сообщество пытается загрузить фото в чужое сообщество,
          а такое наверно нельзя.)
       — Если ты вызываешь с user_id, то он будет загружать в диалог этого пользователя в сообществе,
          но только если он писал сообществу хотя бы раз
       — Если указать 0, то будет загружать в скрытый альбом сообщества, и владелец фотки будет отображатся сообщество
        Следовательно самый нормальный вариант, это для peer_id > 2e9 грузить от лица сообщества, а в других случаях от лица юзера
     */

    private function sendFiles($url, $local_file_path_or_url, $type) {
        $tmp_file = null;

        if (filter_var($local_file_path_or_url, FILTER_VALIDATE_URL) === false) {
            $file = realpath($local_file_path_or_url);
            if (!is_readable($file)) {
                throw new SimpleVkException(0, "Файл для загрузки не найден: $file");
            }

            $mime_type = mime_content_type($file);
            if ($type == 'photo' && !str_starts_with($mime_type, 'image/')) {
                throw new SimpleVkException(0, "Ошибка загрузки файла: файл не является изображением: $file. MimeType: $mime_type");
            }

            $post_fields = [$type => new CURLFile($file)];
        } else {
            $tmp_file = tmpfile();
            $tmp_filename = stream_get_meta_data($tmp_file)['uri'];

            $ch = curl_init($local_file_path_or_url);
            $fp = fopen($tmp_filename, 'wb');

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            $result = curl_exec($ch);
            if (!$result) {
                fclose($fp);
                fclose($tmp_file);
                throw new SimpleVkException(0, "Ошибка скачивания файла: " . curl_error($ch));
            }

            curl_close($ch);
            fclose($fp);

            $mime_type = mime_content_type($tmp_filename);
            if ($type == 'photo' && !str_starts_with($mime_type, 'image/')) {
                fclose($tmp_file);
                throw new SimpleVkException(0, "Ошибка загрузки файла: файл не является изображением: $local_file_path_or_url. MimeType: $mime_type");
            }

            $post_fields = [$type => new CURLFile($tmp_filename, $mime_type, 'file.' . explode('/', $mime_type)[1])];
        }

        try {
            return $this->runRequestWithAttempts($url, $post_fields);
        } catch (SimpleVkException $e) {
            throw $e;
        } finally {
            if (is_resource($tmp_file)) {
                fclose($tmp_file);
            }
        }
    }

    private function saveFile($response, $fileType, $title, $ex_params = []) {
        if ($fileType == 'file') {
            $upload_file = $this->request('docs.save', ['file' => $response['file'], 'title' => $title]);
            $upload_file = $upload_file[$upload_file['type']] ?? current($upload_file);
            return "doc" . $upload_file['owner_id'] . "_" . $upload_file['id'];
        }

        $params = $ex_params + ['photo' => $response['photo'], 'server' => $response['server'], 'hash' => $response['hash']];
        $upload_file = $this->request('photos.saveMessagesPhoto', $params);
        return "photo" . $upload_file[0]['owner_id'] . "_" . $upload_file[0]['id'];
    }

    private function uploadFile(string $upload_url, string $local_file_path_or_url, $title, $fileType, $ex_params = []) {
        $title = $title ?? preg_replace("!.*?/!", '', $local_file_path_or_url);
        $response = $this->sendFiles($upload_url, $local_file_path_or_url, $fileType);
        return $this->saveFile($response, $fileType, $title, $ex_params);
    }

    private function getUploadServer($method, $params) {
        return $this->request($method, $params)['upload_url'];
    }

    public function getMsgAttachmentUploadImage($peer_id, $local_file_path_or_url) {
        $params = ['peer_id' => ($peer_id > 2e9) ? 0 : $peer_id];
        $upload_url = $this->getUploadServer('photos.getMessagesUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, null, 'photo');
    }

    public function getMsgAttachmentUploadVoice($peer_id, $local_file_path_or_url) {
        $params = ['type' => 'audio_message', 'peer_id' => ($peer_id > 2e9) ? 0 : $peer_id];
        $upload_url = $this->getUploadServer('docs.getMessagesUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, 'voice', 'file');
    }

    public function getMsgAttachmentUploadDoc($peer_id, $local_file_path_or_url, $title = null) {
        $params = ['type' => 'doc', 'peer_id' => ($peer_id > 2e9) ? 0 : $peer_id];
        $upload_url = $this->getUploadServer('docs.getMessagesUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, $title, 'file');
    }

    public function getWallAttachmentUploadDoc($id, $local_file_path_or_url, $title = null) {
        $params = $id < 0 ? ['group_id' => -$id] : [];
        $upload_url = $this->getUploadServer('docs.getUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, $title, 'file');
    }

    public function getWallAttachmentUploadImage($id, $local_file_path_or_url) {
        $params = $id < 0 ? ['group_id' => -$id] : ['user_id' => $id];
        $upload_url = $this->getUploadServer('photos.getWallUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, null, 'photo', $params);
    }
}