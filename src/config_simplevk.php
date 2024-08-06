<?php

namespace DigitalStars\SimpleVK;

const SIMPLEVK_VERSION = '3.1.7';
// массив кодов ошибок ВК, при которых идет 5 попыток выполнить этот запрос с перерывом в 10 секунд
const ERROR_CODES_FOR_MANY_TRY = [
    1, //Произошла неизвестная ошибка.
    6, //Слишком много запросов в секунду (флуд контроль)
    9, //Слишком много однотипных действий.
    10, //Произошла внутренняя ошибка сервера (Internal server error)
    14, //Требуется ввод кода с картинки (Captcha)
    38, //превышение лимита запросов (вроде бы)
    121, //Invalid hash при загрузке картинок
];
// максимальное количество попыток загрузки файла
const COUNT_TRY_SEND_FILE = 5;
// Прокси по умолчанию
const PROXY = [];
// Auth
// Запрашиваемые права доступа для токена пользователя по уполчанию
const DEFAULT_SCOPE = "notify,friends,photos,audio,video,stories,pages,status,notes,messages,wall,ads,offline,docs,groups,notifications,stats,email,market,phone,exchange,leads,adsweb,wallmenu,menu";
// User-Agent по умолчанию
const DEFAULT_USERAGENT = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.86 Safari/537.36';
// Приложения ВК
const DEFAULT_APP = [
    "android" => [
        'id' => 2274003,
        'secret' => 'hHbZxrka2uZ6jB1inYsH'
    ],
    'iphone' => [
        'id' => 3140623,
        'secret' => 'VeWdmVclDCtn6ihuP1nt'
    ],
    'ipad' => [
        'id' => 3682744,
        'secret' => 'mY6CDUswIVdJLCD3j15n'
    ],
    'windows_desktop' => [
        'id' => 3697615,
        'secret' => 'AlVXZFMUqyrnABp8ncuU'
    ],
//    'windows_phone' => [
//        'id' => 3502557,
//        'secret' => 'PEObAuQi6KloPM4T30DV'
//    ],
    'vk_messenger' => [
        'id' => 5027722,
        'secret' => 'Skg1Tn1r2qEbbZIAJMx3'
    ]
];

const DEFAULT_ERROR_LOG = E_ALL; //E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR
// Автосохранение авторизации
const AUTO_SAVE_AUTH = True;
// Директория запуска корневого скрипта
DEFINE('DIRNAME', dirname(current(get_included_files())));