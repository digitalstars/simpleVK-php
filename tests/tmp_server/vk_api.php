<?php

declare(strict_types=1);

// Мок-сервер VK API для интеграционных тестов (роутер-режим php -S)
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (str_contains((string) $uri, 'users.get')) {
    header('Content-Type: application/json');
    echo json_encode(['response' => [['id' => 1, 'first_name' => 'Test']]]);
    exit();
}
http_response_code(200);
echo json_encode(['error' => ['error_code' => 100, 'error_msg' => 'unknown method']]);
