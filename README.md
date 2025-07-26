<p align="center">
  <a href="https://simplevk.scripthub.ru/">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/digitalstars/simpleVK-php/master/.github/assets/logo-dark.png">
      <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/digitalstars/simpleVK-php/master/.github/assets/logo-light.png">
      <img alt="Логотип SimpleVK" src="https://raw.githubusercontent.com/digitalstars/simpleVK-php/master/.github/assets/logo-light.png">
    </picture>
  </a>
</p>

<h1 align="center">PHP фреймворк для ботов VK</h1>

<p align="center">
  <strong>Мощная PHP библиотека для создания ботов ВКонтакте.<br>Поддержка VK API, LongPoll, Callback, OAuth2, клавиатур и медиа.</strong>
</p>

<p align="center">
<a href="https://packagist.org/packages/digitalstars/simplevk"><img src="https://img.shields.io/github/v/release/digitalstars/simplevk?color=8992bb" alt="Последний релиз"></a>
<a href="https://vk.com/dev/versions"><img src="https://img.shields.io/badge/VK_API-5.139+-8992bb.svg" alt="Поддержка версий VK API"></a>
<a href="https://packagist.org/packages/digitalstars/simplevk"><img src="https://img.shields.io/packagist/dt/digitalstars/simplevk.svg" alt="Всего установок"></a>
<a href="https://github.com/digitalstars/simpleVK-php/blob/master/LICENSE"><img src="https://img.shields.io/packagist/l/digitalstars/simplevk" alt="Лицензия"></a><br>
<a href="https://simplevk.scripthub.ru/"><img src="https://img.shields.io/badge/-Документация-blue?style=flat&logo=gitbook&logoColor=white" alt="Документация"></a>
<a href="https://vk.me/join/AJQ1dzQRUQxtfd7zSm4STOmt"><img src="https://img.shields.io/badge/-Чат_в_VK-4680C2?style=flat&logo=vk&logoColor=white" alt="Чат в VK"></a>
<a href="https://t.me/your_telegram_channel"><img src="https://img.shields.io/badge/-Чат_в_Telegram-26A5E4?style=flat&logo=telegram&logoColor=white" alt="Чат в Telegram"></a>
</p>

## Уникальные преимущества и возможности

Помимо полной поддержки VK API, **SimpleVK** предоставляет высокоуровневые инструменты и архитектурные решения, которые кардинально ускоряют и упрощают разработку.

- **PSR-{4,11,16} совместимость**
- **Современная архитектура на Атрибутах:** Организуйте код декларативно с помощью PHP 8 Атрибутов. Назначайте обработчики команд, кнопок и middleware так же, как в больших фреймворках (Laravel/Symfony).
- **Конструктор ботов:** Создавайте сложные сценарии, команды и многоуровневые клавиатуры в читабельном цепочном стиле.
- **Продвинутая система отладки:** Получайте подробные отчеты об ошибках с отформатированным трейсом и проблемным участком кода прямо в личные сообщения ВКонтакте.
- **Модуль массовых рассылок:** Отправляйте сообщения с вложениями по всем диалогам или чатам в несколько строк кода.
- **Умные упоминания (placeholder’ы):** Вставляйте теги в сообщения и библиотека сама заменит их на красивые кликабельные упоминания.
- **Обработка команд:** Настраивайте триггеры на текст или регулярные выражения и легко извлекайте параметры из сообщений.

## Полная поддержка VK API

**SimpleVK** предоставляет удобный доступ ко всем стандартным элементам VK API

- **Long Poll API** (для User и Bots)
- **Callback API**
- **Streaming API**
- **Загрузка медиа:** Фото, видео, документы, голосовые сообщения
- **Клавиатуры:** Inline, прикрепленные, карусели
- **Кнопки:** Текстовые, callback, URL, оплата, геолокация
- **Авторизация (OAuth):** Упрощенная работа с токенами.

## Надежность и автоматизация "из коробки"
Вам не нужно думать о типичных проблемах VK API — **SimpleVK** решает их автоматически.
* **Защита от дублей:** Игнорирование дублирующихся событий и повторных событий при долгой обработке.
* **Стабильность соединения:** Повторные запросы при ошибках сети и сбоях/лимитах API.
* **Корректность данных:** Встроенная обработка невалидных JSON и других ошибок API
* **Разбитие длинных сообщений:** Красиво разбивает сообщения выше 4096 символов на несколько частей.

# Подключение
### Используя composer
1\. Установить
```bash
composer require digitalstars/simplevk:~3.0
```

2\. Подключить `autoload.php`
```php
require_once __DIR__.'/vendor/autoload.php';
```
### Вручную
1. Скачать последний релиз c [github](https://github.com/digitalstars/simplevk/tree/testing)
2. Подключить `autoload.php`.  
> Вот так будет происходить подключение, если ваш бот находится в той же папке, что и папка `simplevk-testing`
```php
require_once "simplevk-testing/autoload.php";
```

## Проверка готовности сервера
Чтобы убедится, что вы установили все правильно, и ваш сервер готов к работе с SimpleVK, необходимо создать и запустить следующий скрипт:
```php
<?php
require_once __DIR__.'/vendor/autoload.php';
\DigitalStars\SimpleVK\Diagnostics::run();
```
> Если вы делаете longpoll бота, то запускайте диагностику через консоль  
> Если вы делаете callback бота, то запускайте диагностику через браузер

### Примерный вывод диагностики:
<p align="left">
  <img src="http://images.vfl.ru/ii/1608248228/eea9ef11/32696142.jpg"/>
</p>

## Примеры ботов
### Вызов метода апи
```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\SimpleVK as vk;
$vk = vk::create(ТОКЕН, '5.199'); 
//возвращает сразу по ключу response из ответа
$msg_id = $vk->request('messages.send', [
    'peer_ids' => 1, 
    'message' => 'Привет, ~!fn~', 
    'random_id' => 0
]);
```

### Минимальный Callback
> Бот отвечает на любое сообщение

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\SimpleVK as vk;
$vk = vk::create(ТОКЕН, '5.199')->setConfirm(STR); //STR - строка подтверждения сервера
$vk->msg('Привет, ~!fn~')->send();
```
### Простой Callback

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\SimpleVK as vk;
$vk = vk::create(ТОКЕН, '5.199')->setConfirm(STR); //STR - строка подтверждения сервера
$vk->setUserLogError(ID); //ID - это id vk, кому бот будет отправлять все ошибки, возникние в скрипте
$data = $vk->initVars($peer_id, $user_id, $type, $message); //инициализация переменных из события
if($type == 'message_new') {
    if($message == 'Привет') {
        $vk->msg('Привет, ~!fn~')->send();
    }
}
```
### Простой LongPoll / User LongPoll
> Если указать токен группы - будет LongPoll.  
> Если указать токен пользователя - User LongPoll.  
> А еще можно указать логин и пароль от аккаунта:  
> `new LongPoll(ЛОГИН, ПАРОЛЬ, '5.199');`  
> Но советую создать токен вот по этому [гайду](https://vkhost.github.io/)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\LongPoll;
$vk = LongPoll::create(ТОКЕН, '5.199');
$vk->setUserLogError(ID); //ID - это id vk, кому бот будет отправлять все ошибки, возникние в скрипте
$vk->listen(function () use ($vk) {
    $data = $vk->initVars($peer_id, $user_id, $type, $message); //инициализация переменных из события
    if($type == 'message_new') {
        if($message == 'Привет') {
            $vk->msg('Привет, ~!fn~')->send();
        }
    }
});
```
### Минимальный Бот на конструкторе (Callback)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\Bot;
$bot = Bot::create(ТОКЕН, '5.199');
$bot->cmd('img', '!картинка')->img('cat.jpg')->text('Вот твой кот');
$bot->run(); //запускаем обработку события
```
### Минимальный Бот на конструкторе (LongPoll)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\{Bot, LongPoll};
$vk = LongPoll::create(ТОКЕН, '5.199');
$bot = Bot::create($vk);
$bot->cmd('img', '!картинка')->img('cat.jpg')->text('Вот твой кот');
$vk->listen(function () use ($bot) {
    $bot->run(); //запускаем обработку события
});
```
### Бот с обработкой Команд на конструкторе (Callback)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\{Bot, SimpleVK as vk};
$vk = vk::create(ТОКЕН, '5.199');
$vk->setUserLogError(ID); //ID - это id vk, кому бот будет отправлять все ошибки, возникшие в скрипте
$bot = Bot::create($vk);
//отправит картинку с текстом
$bot->cmd('img', '!картинка')->img('cat.jpg')->text('Вот твой кот');
//обработка команды с параметрами
$bot->cmd('sum', '!посчитай %n + %n')->func(function ($msg, $params) {
    $msg->text($params[0] + $params[1]);
});
//обработка команды по регулярке
$bot->preg_cmd('more_word', "!\!напиши (.*)!")->func(function ($msg, $params) {
    $msg->text("Ваше предложение: $params[1]");
});
$bot->run();
```
### Бот с обработкой Кнопок на конструкторе (Callback)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\{Bot, SimpleVK as vk};
$vk = vk::create(ТОКЕН, '5.199');
$vk->setUserLogError(ID); //ID - это id vk, кому бот будет отправлять все ошибки, возникшие в скрипте
$bot = Bot::create($vk);
$bot->redirect('other', 'first'); //если пришла неизвестная кнопка/текст, то выполняем first
$bot->cmd('first')->kbd([['fish', 'cat']])->text('Выберите животное:'); //срабатывает при нажатии кнопки Начать
$bot->btn('fish', 'Рыбка')->text('Вы выбрали Рыбку!')->img('fish.jpg');
$bot->btn('cat', 'Котик')->text('Вы выбрали Котика!')->img('cat.jpg');
$bot->run();
```
### Бот на конструкторе, с использованием хранилища (Callback)

```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\{Bot, Store, SimpleVK as vk};
$vk = vk::create(ТОКЕН, '5.199');
$bot = Bot::create($vk);
$bot->cmd('cmd1', '!запомни %s')->text('Запомнил!')->func(function ($msg, $params) use ($vk) {
    $vk->initVars($id, $user_id, $payload, $user_id);
    $store = Store::load($user_id); //загружаем хранилище пользователя
    $store->sset('str', $params[0]); //записываем в ключ str его слово
});
$bot->cmd('cmd2', '!напомни')->func(function ($msg, $params) use ($vk) {
    $vk->initVars($id, $user_id, $payload, $user_id);
    $store = Store::load($user_id); //загружаем хранилище пользователя
    $str = $store->get('str'); //выгружаем из его хранилища строку
    $msg->text($str); //устанавливаем текст в экземпляре сообщения
});
$bot->run();
```

### Конфиги
```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use DigitalStars\SimpleVK\{SimpleVK, LongPoll, SimpleVkException, Setting, Auth, Request};

Auth::setProxy('socks5://174.77.111.198:49547', 'password'); //прокси для всех сетевых запросов
Request::errorSuppression(); //подавление генерации throw при ошибках в VK API. Результат выполнения API будет просто возвращатьсся

//SimpleVK::disableSendOK(); //отключает разрыв соединения с VK при получение события. Может потребоваться для дебага через веб
//SimpleVK::retryRequestsProcessing(); //включает обработку повторных запросов для callback, когда скрипт не работал

SimpleVkException::setErrorDirPath(__DIR__ . '/my_errors/'); //установка папки для логирования ошибок бота и API
SimpleVkException::disableWriteError(); //выключить запись логов в файл

Setting::enableUniqueEventHandler(); //включение игнорирования дублирующихся событий (Нужен установленный redis)

//LongPoll::enableInWeb(); //включение возможности запускать скрипт с LongPoll через web (!!! выключить можно будет только убив процесс)

$vk = SimpleVK::create(TOKEN, '5.238');
$vk->setUserLogError(YOUR_VK_ID)->shortTrace(); //отправка всех ошибок в VK и вкл отображение короткого трейса
$vk->setTracePathFilter('C:\your\path'); //вырезание путей из трейса для его укорочения
```

## Больше примеров
Находятся на сайте с документацией в [разделе примеров](https://simplevk.scripthub.ru/v3/install/examples.html), а также в документации есть примеры для каждого метода классов.
