<?php
namespace DigitalStars\SimpleVK;

require __DIR__ . '/src/Compatibility.php';

//Динамическое подключение классов, только при его использовании
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'DigitalStars\\SimpleVK')) {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        $class = str_replace('DigitalStars\\SimpleVK\\', '', $class);
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = $baseDir . "$class.php";
        if (file_exists($file)) {
            require $file;
        }
    }
});
