<?php

namespace DigitalStars\SimpleVK;

use CURLFile, Exception;

require_once('config_simplevk.php');

trait FileUploader {
    use Request;

    private function sendFiles($url, $local_file_path_or_url, $type) {
        if (filter_var($local_file_path_or_url, FILTER_VALIDATE_URL) === false) {
            $file = realpath($local_file_path_or_url);
            if (!is_readable($file)) {
                throw new SimpleVkException(0, "Файл для загрузки не найден: $file");
            }
            $post_fields = [$type => new CURLFile($file)];
        } else {
            $tmp_file = tmpfile();
            $tmp_filename = stream_get_meta_data($tmp_file)['uri'];
            if (!copy($local_file_path_or_url, $tmp_filename)) {
                fclose($tmp_file);
                throw new SimpleVkException(0, "Ошибка скачивания файла: $local_file_path_or_url");
            }
            $mime_type = mime_content_type($tmp_filename);
            $post_fields = [$type => new CURLFile($tmp_filename, $mime_type, 'file.' . explode('/', $mime_type)[1])];
        }

        return $this->runRequestWithAttempts($url, $post_fields);
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
        $upload_url = $this->getUploadServer('photos.getMessagesUploadServer', ['peer_id' => $peer_id]);
        return $this->uploadFile($upload_url, $local_file_path_or_url, null, 'photo');
    }

    public function getMsgAttachmentUploadVoice($peer_id, $local_file_path_or_url) {
        $params = ['type' => 'audio_message', 'peer_id' => $peer_id];
        $upload_url = $this->getUploadServer('docs.getMessagesUploadServer', $params);
        return $this->uploadFile($upload_url, $local_file_path_or_url, 'voice', 'file');
    }

    public function getMsgAttachmentUploadDoc($peer_id, $local_file_path_or_url, $title = null) {
        $params = ['type' => 'doc', 'peer_id' => $peer_id];
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