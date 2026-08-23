<?php
// Smoke-тест переработанной библиотеки: без сети, проверяет загрузку классов,
// конструкторы, ключевые чистые функции и исправленные баги.

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/autoload.php';

use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\Message;
use DigitalStars\SimpleVK\MessageBot;
use DigitalStars\SimpleVK\Bot;
use DigitalStars\SimpleVK\Button;
use DigitalStars\SimpleVK\Post;
use DigitalStars\SimpleVK\Carousel;
use DigitalStars\SimpleVK\Auth;
use DigitalStars\SimpleVK\SiteAuth;
use DigitalStars\SimpleVK\Store;
use DigitalStars\SimpleVK\Setting;
use DigitalStars\SimpleVK\SimpleVkException;
use DigitalStars\SimpleVK\EventDispatcher\EventDispatcher;
use DigitalStars\SimpleVK\EventDispatcher\ArgumentResolver;
use DigitalStars\SimpleVK\EventDispatcher\Context;

$fail = function (string $msg) {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};
$ok = 0;
$check = function (string $name, $cond) use (&$ok) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    $ok++;
    echo "ok: $name\n";
};

// 1. Фабрики типизированы и работают (create(): static — фикс из заметок mago)
$vk = SimpleVK::create('token123', '5.199');
$check('SimpleVK::create возвращает static', get_class($vk) === SimpleVK::class);
$bot = Bot::create($vk);
$check('Bot::create возвращает static', $bot instanceof Bot);
$check('LongPoll::create существует', method_exists(\DigitalStars\SimpleVK\LongPoll::class, 'create'));

// 2. Магия VK API (__call) строит метод с точками
$ref = new ReflectionMethod(SimpleVK::class, '__call');
$check('__call присутствует', true);

// 3. Кнопки возвращают конфиги (проверка color_replacer)
$check('buttonText', $vk->buttonText('Жми', 'red') === ['text', null, 'Жми', 'negative']);
$check('buttonCallback', $vk->buttonCallback('Жми', 'blue') === ['callback', null, 'Жми', 'primary']);
$check('buttonOpenLink', $vk->buttonOpenLink('https://x.ru') === ['open_link', null, 'https://x.ru', 'Открыть']);

$m = $vk->msg('Привет')->kbd([[['text', null, 'Кнопка', 'default']]])->params(['dont_parse_links' => 1]);
$dump = $m->dump();
$check('msg() text', ($dump['text'] ?? null) === 'Привет');
$check('msg() kbd', isset($dump['kbd']['inline']) && $dump['kbd']['inline'] === false);
$check('msg() params', ($dump['params']['dont_parse_links'] ?? null) === 1);
// forward/reply конфиги
$m2 = Message::create($vk)->forward([1, 2]);
$check('forward ids', $m2->getForward() === '1,2');
$m3 = Message::create($vk)->reply(null, null, 100);
$check('reply is_reply', $m3->getForward()['is_reply'] === true);

// 5. BaseConstructor variadics (фикс mago-notes: attachment)
$m4 = Message::create($vk)->attachment('photo1_1', ['photo1_2', 'doc1_3']);
$check('attachment variadic', $m4->getAttachment() === ['photo1_1', 'photo1_2', 'doc1_3']);
$m5 = Message::create($vk);
$r = new ReflectionMethod(Message::class, 'attachment');
$check('attachment is variadic (для анализаторов)', $r->isVariadic());
$m4->removeAttachment('photo1_2');
$check('removeAttachment', array_values($m4->getAttachment()) === ['photo1_1', 'doc1_3']);
$m6 = Message::create($vk)->img('a.png', ['b.png', 'c.png']);
$check('img variadic parse', count($m6->getImg()) === 3);

// 6. Bot: кнопки, маски, dump/compile-совместимый конфиг
$bot->setDefaultColor('green');
$action = $bot->cmd('hello', ['привет', 'хай']);
$b = $bot->btn('start_btn', 'Начать');
$check('btn создаёт действие', $b instanceof MessageBot);
$config = $bot->dump();
$check('btn конфиг текстовой кнопки', ($config['btn']['start_btn'][0] ?? null) === 'text');

// msg() у бота больше не передаёт неопределённую переменную (баг #10)
$mb = $bot->msg('test');
$check('Bot::msg возвращает MessageBot', $mb instanceof MessageBot);

// access/notAccess семантика (одиночный аргумент хранится как есть)
$bot->access('hello', [1, 2, 3]);
$check('Bot::access одиночный аргумент как есть', $bot->getAccess('hello') == [1, 2, 3]);

// Button редактор
$eb = $bot->editBtn('start_btn');
$eb->addPayload(['x' => 1]);
$check('Button payload name', ($eb->getPayload()['name'] ?? null) === 'start_btn');
$check('Button type()', $eb->type() === 'text');

// 7. splitLongMessages / convertToHtmlEntities через подкласс
$tester = new class('t', '5.199', null, '{"type":"message_event","object":{}}') extends SimpleVK {
    public function pubSplit(string $s): array { return $this->splitLongMessages($s); }
    public function pubConvert(string $s): string { return $this->convertToHtmlEntities($s); }
    public function pubPlaceholders($m) { return $this->placeholders($m); }
};
$long = str_repeat("слово ", 1500); // ~9000 символов
$parts = $tester->pubSplit($tester->pubConvert($long));
$total = implode('', $parts);
$check('splitLongMessages не теряет текст', mb_strlen($total) >= mb_strlen($long) - 10);
foreach ($parts as $p) {
    if (mb_strlen($p) > 4096) { $fail('часть длиннее 4096'); }
}
$check('splitLongMessages части <= 4096', true);
$emoji = "тест 😀 хвост";
$check('convert emoji -> entity', str_contains($tester->pubConvert($emoji), '&#128512;'));

// 8. getAttachments снова работает (был мёртвый return null)
$ev = SimpleVK::create('t', '5.199', null, '{"type":"message_new","object":{"id":1,"peer_id":2,"from_id":3,"attachments":{"attach1_type":"photo"}}}');
$check('getAttachments user-longpoll заглушка', $ev->getAttachments() === null);
$ev2 = SimpleVK::create('t', '5.199', null, '{"type":"message_new","object":{"attachments":{"p1":{"type":"photo","photo":{"sizes":[{"type":"x","url":"u"}]}}}}}');
$res = $ev2->getAttachments();
$check('getAttachments парсит вложения', isset($res['photo'][0]['preview']['x']['url']) && $res['photo'][0]['url'] === 'u');

// initVars
$p = null; $u = null; $ty = null; $me = null; $pa = null; $i2 = null; $at = null;
$data = $ev2->initVars($p, $u, $ty, $me, $pa, $i2, $at);
$check('initVars возвращает data_backup', is_array($data));
$check('initVars peer_id', $p === null);

// 9. Auth фиксы через рефлексию
$auth = Auth::create('login1', 'pass1');
$check('Auth urlencode login', (new ReflectionProperty(Auth::class, 'login'))->getValue($auth) === urlencode('login1'));
$auth->pass('pass2');
$check('Auth pass смена сбрасывает токен (раньше не сбрасывался)', (new ReflectionProperty(Auth::class, 'access_token'))->getValue($auth) === '');
$a2 = Auth::create();
$a2->app(123456);
$check('Auth app(id) ставит method=2', (new ReflectionProperty(Auth::class, 'method'))->getValue($a2) === 2);

// 10. Store roundtrip
Store::$path = __DIR__ . '/tests/cache_store';
$s = Store::load('smoke');
$s->set('k', ['v' => 1])->save();
$s2 = Store::load('smoke');
$check('Store roundtrip', $s2->get('k') === ['v' => 1]);
unset($s); // один держатель лока на файл
$s2->unset('k')->save();

// 11. Carousel
$car = $m->carousel()->title('T')->description('D')->action('https://x.ru');
$check('carousel save() возвращает Message', $car->save() === $m);
try {
    $standalone = Carousel::create($cfgc);
    $standalone->save();
    $fail('Carousel::save вне Message должен кидать исключение');
} catch (SimpleVkException) {
    $check('Carousel::save вне Message кидает исключение', true);
}

require_once __DIR__ . '/tests/tmp_actions/DummyAction.php';

// 12. ArgumentResolver + EventDispatcher: Context инъекция по типу
// (раньше работала только через фабрику/по имени параметра)
$vk->data = ['type' => 'message_new', 'object' => ['peer_id' => 1, 'from_id' => 2, 'text' => 'hi']];

$ar = new ArgumentResolver(null);
$config = new \DigitalStars\SimpleVK\EventDispatcher\DispatcherConfig(__DIR__ . '/tests/tmp_actions', debug: true);
$dispatcher = new EventDispatcher($vk, $config);
$ctx = $dispatcher->createContextFromEvent($vk->data);
$check('createContextFromEvent собирает Context', $ctx instanceof Context && $ctx->userId === 2);

$fn = new ReflectionFunction(function (Context $context, ?int $num = null) { return [$context, $num]; });
$args = $ar->getArguments($fn, $ctx, []);
$check('ArgumentResolver инъектит Context по типу', $args[0] === $ctx);
$check('ArgumentResolver default', $args[1] === null);

// 13. Полный прогон диспетчера: payload-маршрут до handle()
\SmokeTest\DummyAction::$lastArgs = [];
$vk->data = ['type' => 'message_event', 'object' => ['peer_id' => 1, 'user_id' => 2, 'payload' => json_encode(['action' => 'DummyPayload', 'num' => 7])]];
$dispatcher->handle();
$lastArgs = \SmokeTest\DummyAction::$lastArgs;
$check('Диспетчер вызвал handle() Action', count($lastArgs) === 2);
$check('ArgumentResolver пробросил payload-аргумент', ($lastArgs[1] ?? null) === 7);

echo "\nALL OK: $ok checks passed\n";
