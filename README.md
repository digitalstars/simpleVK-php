<p align="center">
  <img alt="SimpleVK logo" title="SimpleVK это PHP библиотека быстрой разработки ботов для VK.COM" src="http://images.vfl.ru/ii/1563283715/1c6a23fb/27226348.png"/>
</p>

<p align="center">
<img src="https://img.shields.io/packagist/php-v/digitalstars/vk_api.svg?color=FF6F61" alt="php version">
<img src="https://img.shields.io/badge/VK_API-%3E=%205.124-8992bb.svg" alt="VK api version">
<img src="https://img.shields.io/github/release/digitalstars/vk_api.svg?color=green" alt="Latest Stable Version">
<a href="https://packagist.org/packages/digitalstars/vk_api/"><img src="https://img.shields.io/packagist/dt/digitalstars/vk_api.svg" alt="VK api version"></a>
<img src="https://poser.pugx.org/laravel/framework/license.svg" alt="License">
</p> 

# SimpleVK
[Документация на русском](https://simplevk.scripthub.ru)
--- |  

[Беседа VK](https://vk.me/join/AJQ1dzQRUQxtfd7zSm4STOmt) | [Telegram](https://t.me/vk_api_chat) | [Discord](https://discord.gg/RFqAWRj)
--- | --- | --- |

[Блог со статьями](https://scripthub.ru) | [Разработка ботов на заказ](https://vk.me/scripthub)
--- | --- |
### Оглавление
- [Обзор SimpleVK](#SimpleVK)
- [Подключение](#Подключение)
- [Примеры использования](#Примеры-использования)
- [План развития проекта](#План-развития-проекта)
- [Помощь проекту](#Помощь-проекту)
### Почему SimpleVK?  

Для создания бота потребуется минимум кода, за счёт реализации большинства методов vk api в виде удобных функций.  
Также есть готовые модули, которые облегчают разработку: 
 * Рассылка сообщений
 * Обработка команд
 * Работа с кнопками
### Функционал
В библиотеке поддерживается:
 * Callback API
 * User Long Poll API
 * Bots Long Poll API
 * Execute
 * VKCoin API
 * Создание ботов на пользовательских аккаунтах
 * Работа с голосовыми сообщениями и документами

## Подключение
### Используя composer
```
composer require digitalstars/simplevk
```
```php
require_once "vendor/autoload.php"; //Подключаем библиотеку
```
### Вручную
1. Скачать последний релиз
2. Подключить autoload.php. Вот так будет происходить подключение, если ваш скрипт находится в той же папке, что и папка simplevk-master
```php
require_once "simplevk-master/autoload.php"; //Подключаем библиотеку
```
## Примеры использования
Больше примеров есть на [сайте с документацией](https://simplevk.scripthub.ru)  
Для удобства в каждого бота можно добавлять следущие константы:
```php
const VK_KEY = ''; //токен сообщества или пользователя
const CONFIRM_STR = ''; //ключ авторизации сообщества, который вы получили
const VERSION = '5.101'; //ваша версия используемого api
```
#### Минимальный Callback бот для бесед и сообщества
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
$vk = vk_api::create(VK_KEY, VERSION)->setConfirm(CONFIRM_STR);
$data = $vk->initVars($id, $message, $payload, $user_id, $type); //инициализация переменных
if($type == 'message_new')
  $vk->reply($message);
```
#### Простой Callback бот для бесед и сообщества
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
$vk = vk_api::create(VK_KEY, VERSION)->setConfirm(CONFIRM_STR);
$vk->debug();
$data = $vk->initVars($id, $message, $payload, $user_id, $type); //инициализация переменных
$info_btn = $vk->buttonText('Информация', 'blue', ['command' => 'info']); //создание кнопки
if ($payload) {
    if($payload['command'] == 'info')
        $vk->reply('Тебя зовут %a_full%'); //отвечает пользователю или в беседу
} else
    $vk->sendButton($id, 'Видишь кнопку? Нажми на нее!', [[$info_btn]]); //отправляем клавиатуру с сообщением
```
#### Простой LongPoll бот для юзера 
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
use DigitalStar\vk_api\LongPoll;
$vk = vk_api::create('login', 'password', VERSION);//или используйте токен вместо лог/пас
$vk = new LongPoll($vk);
$vk->listen(function()use($vk){ //longpoll для пользователя
    $vk->on('message_new', function($data)use($vk) { //обработка входящих сообщений
        $vk->initVars($id, $message, $payload, $user_id, $type);
        $vk->reply($message);
    });
});
```
#### Простой LongPoll бот для сообщества
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
use DigitalStar\vk_api\LongPoll;
$vk = vk_api::create(VK_KEY, '5.101');
$vk = new LongPoll($vk);
$vk->listen(function($data)use($vk){ //в $data содержится все данные
    $vk->initVars($id, $message, $payload, $user_id, $type);
    $vk->reply($message);
});
```
#### Callback + Execute
Используется, когда callback скрипт во время выполнения много раз обращается к api, а вам нужно экономить запросы, чтобы не привышать лимит(высоконагруженные боты)
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
use DigitalStar\vk_api\Execute;
$vk = vk_api::create(VK_KEY, VERSION)->setConfirm(CONFIRM_STR);
$vk = new Execute($vk);
$vk->debug();
$data = $vk->initVars($id, $message, $payload, $user_id, $type); //инициализация переменных
$vk->reply($message); //отвечает пользователю или в беседу
```
#### LongPoll + Execute
Лучшая связка для высоконагруженных ботов. Но если вы делаете высоконагруженного бота, лучше посмотрите в сторону NodeJS, он справляется с этим намного лучше за счет асинхронности и многопоточности из коробки.
```php
require_once('vendor/autoload.php');
use DigitalStar\vk_api\vk_api;
use DigitalStar\vk_api\LongPoll;
use DigitalStar\vk_api\Execute;
$vk = vk_api::create(VK_KEY, '5.95');
$vk = new Execute($vk);
$vk = new LongPoll($vk);
$vk->listen(function($data)use($vk){ //в $data содержится все данные события, можно убрать, если не нужен
    $vk->initVars($id, $message, $payload, $user_id, $type); //инициализация переменных
    $vk->reply($message);
});
```
## План развития проекта
- метод для проверки секретного слова
- streaming api
- модуль для удобной обработки комманд в боте
- модуль для удобного написания многоуровневых ботов с кнопками
- возможность в sendImage отдавать ссылку на картинку в интернете для отправки
- метод проверки секретного слова
- более удобная документация на сайте
- Полностью переписать библиотеку на 3.0
- Мобильное приложение с копией сайта, для оффлайн просмота

#### Далекое будущее (до 1 года)
- работа с audio
- работа с историями
- модуль работы с основными платежными системами
- динамические обложки для сообщества(возможно)

## Помощь проекту
- Яндекс.Деньги - [money.yandex.ru/to/410014638432302]()
- Дебетовая карта - 2202201272652211
- Также вы можете помочь проекту `Pull Request`'ом
