<?php
if (PHP_VERSION_ID < 80000) {
    throw new Exception('SimpleVK3 требует PHP версии 8.0.0 или выше. Вы используете версию ' . PHP_VERSION);
}