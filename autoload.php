<?php
namespace DigitalStars\SimpleVK;

require_once __DIR__ . '/src/Compatibility.php';

//Динамическое подключение классов, только при его использовании
spl_autoload_register(static function ($class) {
    $prefix  = 'DigitalStars\\SimpleVK\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

    $prefixLength = strlen($prefix);
    if (strncmp($prefix, $class, $prefixLength) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLength);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
