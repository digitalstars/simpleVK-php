<?php
use \DigitalStars\SimpleVK\Diagnostics;
use DigitalStars\SimpleVK\Utils\EnvironmentDetector;

if (PHP_VERSION_ID < 80200) {
    if(EnvironmentDetector::isWeb()) {
        print '<html><body style="background-color: black; color: white; font-family: monospace;">';
    }
    print Diagnostics::formatText('SimpleVK3 требует PHP версии 8.2.0 или выше. Вы используете версию ' . PHP_VERSION, 'red', need_dot: false);
    Diagnostics::php_iniPatch();
    print Diagnostics::$final_text.Diagnostics::EOL();
    exit();
}

$required_extensions = ['curl', 'mbstring', 'json']; // Список обязательных модулей

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) { //если хоть одного не хватает, вызываем диагностику модулей
        if(EnvironmentDetector::isWeb()) {
            print '<html><body style="background-color: black; color: white; font-family: monospace;">';
        }
        print Diagnostics::formatText("SimpleVK не может работать, т.к. не установлены или не включены обязательные модули:", 'red', need_dot: false);
        Diagnostics::php_iniPatch();
        Diagnostics::checkModules();
        print Diagnostics::$final_text.Diagnostics::EOL();
        exit();
    }
}