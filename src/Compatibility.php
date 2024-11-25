<?php
if (PHP_VERSION_ID < 80000) {
    throw new Exception('SimpleVK3 требует PHP версии 8.0.0 или выше. Вы используете версию ' . PHP_VERSION);
}

if (!extension_loaded('curl')) {
    $ini_file = php_ini_loaded_file();
    $ini_msg = $ini_file
        ? "Расположение файла конфигурации: {$ini_file}."
        : '';
    $php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $install_command = "sudo apt install php{$php_version}-curl";

    print  "SimpleVK3 требует наличия расширения php-curl. Установите и активируйте его в php.ini\n" .
        "Команда для Ubuntu/Debian: {$install_command}\n".
        $ini_msg . "\n";

    throw new \Exception();
}