<?php

namespace DigitalStars\SimpleVK;

use DigitalStars\SimpleVK\Utils\EnvironmentDetector;

require_once 'config_simplevk.php';

/**
 * Самодиагностика окружения для SimpleVK: PHP, модули, пинг VK, права на файлы.
 */
class Diagnostics {
    public static string $final_text = '';

    /** Запускает полный набор проверок и выводит отчёт. */
    public static function run(): void {
        self::initialize();
        self::$final_text .= self::getEnvironmentInfoString();
        self::php_iniPatch();
        self::addSystemInfo();
        self::checkCurl();
        self::testPingVK();
        self::checkFilePermissions();
        self::checkModules();
        self::finish();
    }

    private static function initialize(): void {
        if (EnvironmentDetector::isWeb()) {
            if (isset($_GET['type'])) {
                switch ($_GET['type']) {
                    case 'check_send_ok':
                        exit(self::sendOK());
                    case 'check_headers':
                        exit(self::checkHeaders());
                }
            }

            self::$final_text .= '<html><body style="background-color: black">'
                . '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>';
        }

        $latest_version = self::getLatestVersion();
        if (!$latest_version) {
            $new_version_str = self::formatText("(Не удалось узнать актуальную версию)", 'yellow', '', need_dot: false);
        } else {
            if (version_compare(SIMPLEVK_VERSION, $latest_version, '<')) {
                $new_version_str = self::formatText("(Доступна $latest_version)", 'yellow', '', need_dot: false);
            } else {
                $new_version_str = self::formatText("(Актуальная версия)", 'green', '', need_dot: false);
            }
        }

        print self::formatText('Диагностика системы для работы с SimpleVK ' . SIMPLEVK_VERSION . " $new_version_str", 'cyan', need_dot: false)
            . self::formatText('Проверяем пинг до api.vk.com и steal time...', 'cyan', need_dot: false);
        self::$final_text .= self::formatText('Информация о системе', 'cyan', need_dot: false);
    }

    private static function getEnvironmentInfoString(): string {
        switch (EnvironmentDetector::getEnvironment()) {
            case EnvironmentDetector::ENV_WEB:
                $sapi = PHP_SAPI;
                $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? ' - ' . $_SERVER['SERVER_SOFTWARE'] : '';
                $message = sprintf('Запущен через: Веб-сервер (SAPI: %s%s)', $sapi, $serverSoftware);
                return self::formatText($message, 'green');

            case EnvironmentDetector::ENV_CLI_INTERACTIVE:
                $user = get_current_user();
                $message = sprintf('Запущен через: Интерактивная командная строка (CLI) от пользователя "%s"', $user);
                return self::formatText($message, 'green');

            case EnvironmentDetector::ENV_CLI_NON_INTERACTIVE:
                $message = 'Запущен через: Неинтерактивная командная строка (вероятно, cron или демон)';
                return self::formatText($message, 'yellow');
        }

        return self::formatText('Не удалось определить среду выполнения.', 'red');
    }

    /** Проверяет расположение php.ini. */
    public static function php_iniPatch(): void {
        $ini_file = php_ini_loaded_file();
        if ($ini_file) {
            self::$final_text .= self::formatText("Расположение файла конфигурации: {$ini_file}.", 'yellow');
            if (!str_ends_with($ini_file, '.ini')) {
                self::$final_text .= self::formatText("WARNING: Файл конфигурации не заканчивается на .ini . Возможно, у вас неправильно настроен интерпритатор в IDE или проблема в параметрах запуска", 'red');
            }
        } else {
            self::$final_text .= self::formatText('Не удалось определить расположение php.ini.', 'yellow');
        }
    }

    private static function addSystemInfo(): void {
        self::$final_text .= self::formatText('PHP: ' . PHP_VERSION, PHP_MAJOR_VERSION >= 8 ? 'green' : 'red');
        self::$final_text .= self::formatText('ОС: ' . self::getOSVersion(), self::isWindows() ? 'yellow' : 'green');
        self::$final_text .= self::getCpuInfo();
        self::$final_text .= self::getMemoryInfo();
    }

    private static function getLatestVersion(): ?string {
        if (!extension_loaded('curl')) {
            return null;
        }
        $url = 'https://api.github.com/repos/digitalstars/simplevk/releases/latest';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36');

        $response = curl_exec($ch);
        unset($ch);

        $data = json_decode((string)$response, true);

        if (isset($data['tag_name'])) {
            return ltrim((string)$data['tag_name'], 'v');
        }

        return null;
    }

    private static function getOSVersion(): string {
        if (self::isWindows()) {
            // Получаем название ОС
            $output = shell_exec('powershell -command "(Get-WmiObject -class Win32_OperatingSystem).Caption"');
            $osCaption = explode(' ', trim((string)$output), 2)[1] ?? '';

            // Получаем версию из реестра (например, 24H2)
            $versionOutput = shell_exec('powershell -command "(Get-ItemProperty \"HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\").DisplayVersion"');
            $versionCode = trim((string)$versionOutput);

            // Получаем номер сборки
            $buildOutput = shell_exec('powershell -command "(Get-WmiObject -class Win32_OperatingSystem).Version"');
            $buildNumber = trim((string)$buildOutput);

            if ($osCaption && $versionCode && $buildNumber) {
                return "$osCaption ($versionCode) - build $buildNumber";
            }

            return 'Windows'; // Если не удалось получить данные, просто выводим Windows
        }

        // Для Linux
        $output = shell_exec('lsb_release -d'); // Ubuntu, Debian и подобные
        if (!$output) {
            // В случае, если lsb_release не установлен
            $output = shell_exec('grep PRETTY_NAME /etc/os-release | cut -d= -f2- | tr -d \'"\'');
        }
        return $output ? 'Linux (' . trim(str_replace('Description:', '', (string)$output)) . ')' : PHP_OS;
    }

    private static function isWindows(): bool {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Форматирует текст цветом под текущую среду (ANSI для CLI, HTML для веб).
     */
    public static function formatText(string $text, string $color, string $separator = PHP_EOL, bool $need_dot = true): string {
        if ($separator === PHP_EOL) {
            $separator = self::EOL();
        }

        if (EnvironmentDetector::isInteractiveCli()) {
            $colors = [
                'red' => "\033[0;31m",
                'green' => "\033[0;32m",
                'yellow' => "\033[1;33m",
                'cyan' => "\033[0;36m"
            ];
            return ($colors[$color] ?? '') . ($need_dot ? '· ' : '') . $text . $separator . "\033[0m";
        }

        return '<span style="color: ' . $color . ($need_dot ? '">· ' : '">') . $text . '</span>' . $separator;
    }

    private static function checkCurl(): void {
        $curlStatus = is_callable('curl_init') ? 'доступен' : 'не доступен';
        self::$final_text .= self::formatText("сURL: $curlStatus", $curlStatus === 'доступен' ? 'green' : 'red');
    }

    private static function testPingVK(): void {
        if (!function_exists('curl_init')) return;

        $min = null;
        $max = null;
        $pingTimes = [];
        $big_ping = false;

        for ($i = 0; $i < 10; $i++) {
            $ping = self::getPingTime();

            if (!$ping) {
                $big_ping = true;
                break;
            }

            if (!$min || $ping < $min) {
                $min = $ping;
            }
            if (!$max || $ping > $max) {
                $max = $ping;
            }
            $pingTimes[] = $ping;
        }

        if ($big_ping) {
            $pingMessage = "Пинг к api.vk.com: >300ms";
            self::$final_text .= self::formatText($pingMessage, 'red');
            return;
        }

        $min = round($min * 1000, 1);
        $max = round($max * 1000, 1);
        $averagePing = round(array_sum($pingTimes) / count($pingTimes) * 1000, 1);
        $count = count($pingTimes);
        $pingMessage = "Пинг к api.vk.com: $min / $averagePing / $max мс (мин/средн/макс) ($count попыток)";
        self::$final_text .= self::formatText($pingMessage, self::getPingStatusColor($averagePing));
    }

    private static function getPingTime(): float|false {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'api.vk.com');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300); // тайм-аут в 300 мс
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        unset($ch);

        if (!$result) {
            return false;
        }

        return (float)$info['total_time'];
    }

    private static function getPingStatusColor(float $ping): string {
        if ($ping <= 40) {
            return 'green';
        }

        if ($ping < 100) {
            return 'yellow';
        }

        return 'red';
    }

    private static function checkFilePermissions(): void {
        self::$final_text .= self::EOL() . self::formatText("Проверка работы с файлами", 'cyan', need_dot: false);

        if (ini_get('open_basedir')) {
            self::$final_text .= self::formatText('open_basedir != none. Возможны ошибки.', 'red');
        } else {
            self::$final_text .= self::formatText('open_basedir == none', 'green');
        }

        self::testFileOperations();
    }

    private static function testFileOperations(): void {
        $baseDir = __DIR__;
        $uniqueSuffix = uniqid('test_simplevk_', true);
        $testDir = $baseDir . DIRECTORY_SEPARATOR . $uniqueSuffix;

        if (!mkdir($testDir, 0755, true) && !is_dir($testDir)) {
            self::$final_text .= self::formatText("Не удалось создать папку $testDir", 'red');
            return;
        }

        self::$final_text .= self::formatText("Создание папок: разрешено", 'green');

        $testFile = $testDir . DIRECTORY_SEPARATOR . 'test.txt';

        if (@file_put_contents($testFile, '123') === false) {
            self::$final_text .= self::formatText("Не удалось создать файл $testFile", 'red');
            return;
        }

        self::$final_text .= self::formatText("Создание файлов: разрешено", 'green');

        $content = @file_get_contents($testFile);
        if ($content === false) {
            self::$final_text .= self::formatText("Чтение файлов: запрещено", 'red');
        } else {
            self::$final_text .= self::formatText("Чтение файлов: разрешено", 'green');
        }

        self::cleanupTestFiles($testFile, $testDir);
    }

    private static function cleanupTestFiles(string $file, string $dir): void {
        if (file_exists($file)) {
            if (!@unlink($file)) {
                self::$final_text .= self::formatText("Удаление файлов: запрещено", 'red');
            } else {
                self::$final_text .= self::formatText("Удаление файлов: разрешено", 'green');
            }
        }

        if (file_exists($dir)) {
            if (!@rmdir($dir)) {
                self::$final_text .= self::formatText("Удаление папок: запрещено", 'red');
            } else {
                self::$final_text .= self::formatText("Удаление папок: разрешено", 'green');
            }
        }
    }

    /**
     * Проверяет наличие обязательных/рекомендуемых/популярных модулей.
     */
    public static function checkModules(): void {
        self::$final_text .= self::EOL() . self::formatText("Проверка активации обязательных модулей в php.ini", 'cyan', need_dot: false);
        self::checkModuleGroup(['curl', 'json', 'mbstring']);
        self::$final_text .= self::EOL() . self::formatText("Проверка активации рекомендуемых модулей в php.ini", 'cyan', need_dot: false);

        self::checkModuleGroup(['ffi'], add_eol: false);
        self::$final_text .= self::formatText(' (Используется в модуле на С для ускорения проверки и разбивки отправляемых сообщений)', 'yellow', '', false) . self::EOL();
        self::checkModuleGroup(['redis'], add_eol: false);
        self::$final_text .= self::formatText(' (Используется для обнаружения дублирующихся событий от VK API и их игнорирования)', 'yellow', '', false) . self::EOL();
        if (!self::isWindows()) {
            self::checkModuleGroup(['pcntl', 'posix'], add_eol: false);
            self::$final_text .= self::formatText(' (Автоматическая многопоточная обработка событий через Longpoll)', 'yellow', '', false) . self::EOL();
        }

        self::$final_text .= self::EOL() . self::formatText("Проверка активации других часто используемых модулей в php.ini", 'cyan', need_dot: false);
        self::checkModuleGroup(['mysqli', 'pdo_mysql', 'sqlite3', 'pdo_sqlite', 'pgsql', 'pdo_pgsql', 'opcache', 'openssl', 'apcu']);
    }

    private static function checkModuleGroup(array $modules, bool $add_eol = true): void {
        $modules_str = '';
        $count = count($modules);
        foreach ($modules as $key => $module) {
            $status = self::checkModule((string)$module);
            if ($key + 1 == $count) {
                $modules_str .= self::formatText((string)$module, $status, '', false);
            } else {
                $modules_str .= self::formatText((string)$module, $status, ', ', false);
            }
        }
        self::$final_text .= $modules_str . ($add_eol ? self::EOL() : '');
    }

    private static function checkModule(string $moduleName): string {
        return extension_loaded($moduleName) ? 'green' : 'red';
    }

    private static function getMemoryInfo(): string {
        if (self::isWindows()) {
            // Команда для получения информации о RAM через PowerShell
            $command = 'powershell -command "Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize, FreePhysicalMemory"';

            $output = shell_exec($command);
            if (empty($output)) {
                return self::formatText('Не удалось получить информацию о памяти', 'yellow');
            }

            $lines = explode("\n", trim((string)$output));
            if (count($lines) < 3) {
                return self::formatText('Не удалось получить информацию о памяти', 'yellow');
            }

            $memoryData = preg_split('/\s+/', trim($lines[2]));
            if ($memoryData === false || count($memoryData) < 2) {
                return self::formatText('Не удалось получить информацию о памяти', 'yellow');
            }

            // Преобразуем значения из килобайт в гигабайты
            $totalMemoryGB = round(((float)$memoryData[0]) / 1024 / 1024, 2);
            $freeMemoryGB = round(((float)$memoryData[1]) / 1024 / 1024, 2);

            // Вычисляем занятую память и процент использования
            $usedMemoryGB = round($totalMemoryGB - $freeMemoryGB, 2);
            $usedPercentage = $totalMemoryGB > 0 ? round(($usedMemoryGB / $totalMemoryGB) * 100, 2) : 0;

            return self::formatText("ОЗУ занято: $usedMemoryGB / $totalMemoryGB GB ($usedPercentage%)", 'green');
        }

        // Linux
        $free = trim((string)shell_exec('free -m')); // Получаем данные сразу в мегабайтах
        $free_arr = explode("\n", $free);
        $mem = preg_split('/\s+/', $free_arr[1] ?? ''); // Разделяем строку по пробелам
        if ($mem === false || count($mem) < 3) {
            return self::formatText("Не удалось получить информацию об ОЗУ", 'yellow');
        }

        $memtotal = round(((float)$mem[1]) / 1000, 2); // Переводим в гигабайты
        $memused = round(((float)$mem[2]) / 1000, 2);

        if ($memtotal) {
            return self::formatText("ОЗУ занято: " . $memused . " / $memtotal GB (" . round($memused / $memtotal * 100) . "%)", 'green');
        }

        return self::formatText("Не удалось получить информацию об ОЗУ", 'yellow');
    }

    private static function getCpuInfo(): string {
        $os = PHP_OS_FAMILY;
        $cpuName = '';
        $cpuCores = 0;
        $cpuLoad = 0;
        $cpuFreq = '';
        $stealPercent = null;
        $stealRecent = null;
        $return_text = '';

        if (self::isWindows()) {
            $cpuInfoCommand = 'powershell -command "Get-CimInstance Win32_Processor | Select-Object Name, NumberOfCores, LoadPercentage, MaxClockSpeed"';
            $output = shell_exec($cpuInfoCommand);

            if (!empty($output)) {
                $lines = explode("\n", trim((string)$output));
                if (count($lines) > 2) {
                    $data = preg_split('/\s{2,}/', trim($lines[2]));
                    if ($data !== false) {
                        $cpuName = $data[0];
                        $cpuCores = (int)($data[1] ?? 0);
                        $cpuLoad = $data[2] ?? 'N/A';
                        $cpuFreq = isset($data[3]) ? $data[3] . ' MHz' : '';
                    }
                }
                $return_text .= self::formatText("Процессор: $cpuName", 'green');
                $return_text .= self::formatText("Количество ядер: $cpuCores" . ($cpuFreq ? " ({$cpuFreq})" : ""), 'green');
                $return_text .= self::formatText("Загруженность процессора: $cpuLoad%", 'green');
            } else {
                $return_text .= self::formatText("Не удалось получить информацию о процессоре", 'yellow');
            }
        } elseif ($os === 'Linux') {
            $cpuName = trim((string)shell_exec("grep 'model name' /proc/cpuinfo | head -1 | cut -d ':' -f2"));
            $cpuCores = (int)shell_exec("nproc");
            $cpuFreqRaw = shell_exec("lscpu | grep 'CPU MHz' | awk '{print \$3}'");
            $cpuFreq = $cpuFreqRaw ? round((float)$cpuFreqRaw) . ' MHz' : '';
            $loadAvgValues = explode(' ', (string)file_get_contents('/proc/loadavg'));
            $cpuLoad = round((float)($loadAvgValues[1] ?? 0), 2);

            // STEAL TIME (TOTAL)
            $stat = file_get_contents('/proc/stat');
            preg_match('/^cpu\s+(.+)$/m', (string)$stat, $matches);
            if (!empty($matches[1])) {
                $fields = preg_split('/\s+/', trim($matches[1]));
                $stealTicks = isset($fields[7]) ? (int)$fields[7] : 0;
                $totalTicks = array_sum(array_slice(array_map('intval', $fields), 0, 10));
                if ($totalTicks > 0) {
                    $stealPercent = round(($stealTicks / $totalTicks) * 100, 2);
                }
            }

            // STEAL TIME (5 SEC INTERVAL)
            $first = self::getStatSnapshot();
            sleep(5);
            $second = self::getStatSnapshot();

            if ($first && $second) {
                $deltaTotal = $second['total'] - $first['total'];
                $deltaSteal = $second['steal'] - $first['steal'];
                if ($deltaTotal > 0) {
                    $stealRecent = round(($deltaSteal / $deltaTotal) * 100, 2);
                }
            }

            $return_text .= $cpuName ? self::formatText("Процессор: $cpuName", 'green') : self::formatText("Не удалось получить информацию о названии процессора", 'yellow');
            $return_text .= $cpuCores ? self::formatText("Количество ядер: $cpuCores" . ($cpuFreq ? " ({$cpuFreq})" : ""), 'green') : self::formatText("Не удалось получить информацию о количестве ядер", 'yellow');
            $return_text .= self::formatText("Средняя нагрузка за 5 минут: $cpuLoad%", 'green');

            // Общий steal time
            if ($stealPercent !== null) {
                $stealText = "Steal Time (с момента запуска): $stealPercent%";

                if ($stealPercent <= 2) {
                    $return_text .= self::formatText($stealText, 'green');
                } elseif ($stealPercent <= 10) {
                    $return_text .= self::formatText($stealText, 'yellow');
                } else {
                    $return_text .= self::formatText($stealText, 'red');
                }
            } else {
                $return_text .= self::formatText("Не удалось получить Steal Time (с момента запуска)", 'yellow');
            }

            // Steal time за последние 5 секунд
            if ($stealRecent !== null) {
                $stealText = "Steal Time (5c.): $stealRecent%";

                if ($stealRecent <= 2) {
                    $return_text .= self::formatText($stealText, 'green');
                } elseif ($stealRecent <= 10) {
                    $return_text .= self::formatText($stealText, 'yellow');
                } else {
                    $return_text .= self::formatText($stealText, 'red');
                }
            } else {
                $return_text .= self::formatText("Не удалось получить Steal Time (5с.)", 'yellow');
            }
        } else {
            $return_text .= self::formatText("Не удалось получить информацию о процессоре", 'yellow');
        }

        return $return_text;
    }

    /** @return array{steal: int, total: int}|null */
    private static function getStatSnapshot(): ?array {
        $stat = @file_get_contents('/proc/stat');
        if (!$stat) return null;

        preg_match('/^cpu\s+(.+)$/m', $stat, $matches);
        if (!isset($matches[1])) return null;

        $fields = preg_split('/\s+/', trim($matches[1]));
        $steal = isset($fields[7]) ? (int)$fields[7] : 0;
        $total = array_sum(array_slice(array_map('intval', $fields), 0, 10));

        return ['steal' => $steal, 'total' => $total];
    }

    /** Выводит финальный отчёт (в веб добавляет сетевые проверки). */
    public static function finish(): void {
        if (EnvironmentDetector::isWeb()) {
            self::$final_text .= self::EOL() . self::formatText("Проверка работы веб-сервера", 'cyan', need_dot: false);
            self::$final_text .= self::addNetworkChecks();
            self::$final_text .= self::EOL() . self::formatText("Не забудьте удалить этот скрипт после проверки!", 'yellow', need_dot: false);
        }

        echo self::$final_text . self::EOL();
    }

    private static function addNetworkChecks(): string {
        return <<<HTML
<span id="test_send_ok" style="color: white">· Выполняется фоновая проверка...</span><br>
<span id="test_check_header" style="color: white">· Выполняется фоновая проверка...</span><br>
<span id="test_server" style="color: white">· Выполняется фоновая проверка...</span><br>
<script type="text/javascript">
    \$.ajax({ url: window.location.href, data: "type=check_send_ok", success: function(response, status, xhr) {
        let test_send_ok = \$("#test_send_ok"),  test_server = \$("#test_server"), server = xhr.getResponseHeader("server");
        test_send_ok.text(response === "ok" ? "· PHP может разрывать соединение с ВК" : "· PHP не может разрывать соединение с ВК (sendOK() не работает)");
        test_send_ok.css("color", response === "ok" ? "green" : "red");

        test_server.text(test_server ? "· Веб-сервер: " + server : "· Веб-сервер: Нет данных");
        test_server.css("color", test_server ? "green" : "red");
    }});
    \$.ajax({
        url: window.location.href,
        data: "type=check_headers",
        headers: {"Retry-After": "test_1", "X-Retry-Counter": "test_2"},
        success: function(response) {
            let test_headers = \$("#test_check_header");
            test_headers.text(response === "ok" ? "· PHP получает кастомные заголовки" : "· PHP не получает кастомные заголовки");
            test_headers.css("color", response === "ok" ? "green" : "red");
        }
  });
</script>
</body></html>
HTML;
    }

    public static function EOL(): string {
        if (EnvironmentDetector::isWeb()) {
            return '<br>';
        }

        return PHP_EOL;
    }

    /** Отправляет 'ok' и разрывает соединение (для проверки sendOK). */
    private static function sendOK(): bool {
        echo 'Test?';
        set_time_limit(0);
        ini_set('display_errors', 'Off');
        ob_end_clean();

        // для Nginx
        if (is_callable('fastcgi_finish_request')) {
            echo 'ok';
            session_write_close();
            fastcgi_finish_request();
            return true;
        }
        // для Apache
        ignore_user_abort(true);

        ob_start();
        header('Content-Encoding: none');
        header('Content-Length: 2');
        header('Connection: close');
        echo 'ok';
        ob_end_flush();
        flush();
        return true;
    }

    /** Проверяет получение кастомных заголовков Retry-After/X-Retry-Counter. */
    private static function checkHeaders(): string {
        $hasRetryAfter = ($_SERVER['HTTP_RETRY_AFTER'] ?? '') == 'test_1';
        $hasRetryCounter = ($_SERVER['HTTP_X_RETRY_COUNTER'] ?? '') == 'test_2';

        return ($hasRetryAfter && $hasRetryCounter) ? 'ok' : 'no';
    }
}
