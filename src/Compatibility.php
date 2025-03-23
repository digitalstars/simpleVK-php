<?php
use \DigitalStars\SimpleVK\Diagnostics;

if (PHP_VERSION_ID < 80000) {
    print Diagnostics::formatText('SimpleVK3 требует PHP версии 8.0.0 или выше. Вы используете версию ' . PHP_VERSION, 'red', need_dot: false);
    Diagnostics::php_iniPatch();
    Diagnostics::finish();
    exit();
}

$required_extensions = ['curl', 'mbstring', 'json']; // Список обязательных модулей

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) { //если хоть одного не хватает, вызываем диагностику модулей
        print Diagnostics::formatText("SimpleVK не может работать, т.к. не установлены или не включены обязательные модули:", 'red', need_dot: false);
        Diagnostics::php_iniPatch();
        Diagnostics::checkModules();
        Diagnostics::finish();
        exit();
    }
}