<?php

namespace DigitalStars\SimpleVK;

require_once 'config_simplevk.php';

class Diagnostics {

    private static $canCreateFile = 0;
    private static $canWriteToFile = 0;
    private static $final_text = '';

    static public function run() {
        $EOL = self::EOL();

        if (PHP_SAPI != 'cli') {
            if (isset($_GET['type']) && $_GET['type'] == 'check_send_ok')
                exit(self::sendOK());
            if (isset($_GET['type']) && $_GET['type'] == 'check_headers')
                exit((isset($_SERVER['HTTP_RETRY_AFTER']) && $_SERVER['HTTP_RETRY_AFTER'] == 'test_1' &&
                    isset($_SERVER['HTTP_X_RETRY_COUNTER']) && $_SERVER['HTTP_X_RETRY_COUNTER'] == 'test_2')
                    ? 'ok' : 'no');
            self::$final_text .= '<html><body style="background-color: black">' .
                '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>';
        }

        self::$final_text .= self::cyan("Диагностика системы для работы с SimpleVK " . SIMPLEVK_VERSION, $EOL, '') . $EOL;
        self::$final_text .= self::cyan("Информация о системе", $EOL, '');

        if (PHP_MAJOR_VERSION >= 8)
            self::$final_text .= self::green("PHP: " . PHP_VERSION);
        else
            self::$final_text .= self::red("PHP: " . PHP_VERSION);

        self::$final_text .= self::webServerOrCli();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            self::$final_text .= self::yellow("ОС: " . PHP_OS . " (На Windows модули pcntl и posix недоступны, поэтому параллельный режим longpoll не будет работать)");
        } else {
            self::$final_text .= self::green("ОС: " . PHP_OS);
        }

        if (defined('PHP_OS_FAMILY')) {
            if (PHP_OS_FAMILY == 'Windows') {
                self::$final_text .= self::yellow("ОС_FAMILY: " . PHP_OS_FAMILY);
            } else {
                self::$final_text .= self::green("ОС_FAMILY: " . PHP_OS_FAMILY);
            }
        }

        self::$final_text .= self::num_cpus();
        self::$final_text .= self::get_memory();
        self::$final_text .= self::get_memory(2);

        if (is_callable('curl_init')) {
            self::$final_text .= self::green("CURL: доступен");
        } else {
            self::$final_text .= self::red("CURL: не доступен");
        }
        if (PHP_SAPI != 'cli') {
            self::$final_text .= '<span id="test_server" style="color: white">· Веб-сервер: Выполняется фоновая проверка...</span><br>';
        }
        self::$final_text .= self::testPingVK();

        self::$final_text .= $EOL . self::cyan("Проверка работы с файлами", $EOL, '');

        if(ini_get('open_basedir')) {
            self::$final_text .= self::red("open_basedir != none. Из-за этого могут быть ошибки.");
        } else {
            self::$final_text .= self::green("open_basedir == none");
        }

        self::checkFileJob();


        self::$final_text .= $EOL . self::cyan("Проверка активации Обязательных модулей в php.ini", $EOL, '');
        self::checkImportantModules();

        self::$final_text .= $EOL;

        self::$final_text .= $EOL . self::cyan("Проверка активации Опциональных модулей в php.ini", $EOL, '');
        self::checkNoImportantModules();

        self::$final_text .= $EOL;

        if (PHP_SAPI != 'cli') {
            self::$final_text .= $EOL . self::cyan("Проверка работы с сетью", $EOL, '') .
                '<span id="test_send_ok" style="color: white">· Выполняется фоновая проверка...</span><br>' .
                '<span id="test_check_header" style="color: white">· Выполняется фоновая проверка...</span><br>' .
                $EOL . self::yellow("Не забудьте удалить скрипт, чтобы другие не смогли узнать информацию о вашем сервере", $EOL, '') .
                '<script type="text/javascript">
    $.ajax({
      url: window.location.href,
      data: "type=check_send_ok",
      success: function (response, status, xhr) {
        let test_send_ok = $("#test_send_ok"), 
            test_server = $("#test_server")
            server = xhr.getResponseHeader("server");
        if (response == "ok") {
          test_send_ok.text("· PHP может разрывать соединение с ВК");
          test_send_ok.css("color", "green");
        } else {
          test_send_ok.text("· PHP не может разрывать соединение с ВК. sendOK() не работает");
          test_send_ok.css("color", "red");
        }
        if (server) {
          test_server.text("· Веб-сервер: " + server);
          test_server.css("color", "green");
        } else {
          test_server.text("· Веб-сервер: Нет данных");
          test_server.css("color", "red");
        }
      }
    });
  $.ajax({
      url: window.location.href,
      data: "type=check_headers",
      headers: {"Retry-After": "test_1", "X-Retry-Counter": "test_2"},
      success: function (response) {
        let test_headers = $("#test_check_header");
        if (response == "ok") {
          test_headers.text("· PHP получает кастомные заголовки");
          test_headers.css("color", "green");
        } else {
          test_headers.text("· PHP не получает кастомные заголовки на этом веб-сервере");
          test_headers.css("color", "red");
        }
      }
    });
</script>
</body></html>';
        }

        print self::$final_text;
    }

    private static function sendOK() {
        echo 'Test?';
        set_time_limit(0);
        ini_set('display_errors', 'Off');
        ob_end_clean();

        // для Nginx
        if (is_callable('fastcgi_finish_request')) {
            echo 'ok';
            session_write_close();
            fastcgi_finish_request();
            return True;
        }
        // для Apache
        ignore_user_abort(true);

        PHP_EOL;
        ob_start();
        header('Content-Encoding: none');
        header('Content-Length: 2');
        header('Connection: close');
        echo 'ok';
        ob_end_flush();
        flush();
        return True;
    }

    private static function EOL() {
        if (PHP_SAPI != 'cli') {
            return '<br>';
        } else {
            return PHP_EOL;
        }
    }

    private static function checkFileJob() {
        @rmdir('test_simplevk357475');
        $result = @mkdir('test_simplevk357475');
        if ($result === false) {
            self::$final_text .= self::red("Не удалось создать папку test_simplevk357475");
        } else {
            self::$final_text .= self::green("Создание папок: разрешено");
            $result = @file_put_contents('test_simplevk357475/test.txt', '123');
            if ($result === false) {
                self::$final_text .= self::red("Не удалось создать файл ./test_simplevk357475/test.txt");
            } else {
                self::$final_text .= self::green("Создание файлов: разрешено");
                self::$canCreateFile = 1;
                $result = @file_get_contents('test_simplevk357475/test.txt');
                if ($result === false) {
                    self::$final_text .= self::red("Чтение файлов: запрещено");
                } else {
                    self::$final_text .= self::green("Чтение файлов: разрешено");
                    if ($result == 123) {
                        self::$final_text .= self::green("Запись в файлы: разрешено");
                        self::$canWriteToFile = 1;
                    } else {
                        self::$final_text .= self::red("Запись в файлы: запрещено");
                    }
                }
            }
        }
        self::deleteTest();
    }

    private static function deleteTest() {
        if (file_exists('test_simplevk357475/test.txt')) {
            $result = @unlink('test_simplevk357475/test.txt');
            if ($result === false)
                self::$final_text .= self::red("Удаление файлов: запрещено");
            else
                self::$final_text .= self::green("Удаление файлов: разрешено");
        }

        if (file_exists('test_simplevk357475')) {
            $result = @rmdir('test_simplevk357475');
            if ($result === false)
                self::$final_text .= self::red("Удаление папок: запрещено");
            else
                self::$final_text .= self::green("Удаление папок: разрешено");
        }
    }

    private static function red($string, $add = PHP_EOL, $add_first = '· '): string {
        if (PHP_SAPI == 'cli')
            return "\033[" . "0;31m" . $add_first . $string . $add . "\033[0m";
        else {
            if ($add == PHP_EOL)
                $add = '<br>';
            return '<span style="color: red">' . $add_first . $string . $add . '</span>';
        }
    }

    private static function green($string, $add = PHP_EOL, $add_first = '· '): string {
        if (PHP_SAPI == 'cli')
            return "\033[" . "0;32m" . $add_first . $string . $add . "\033[0m";
        else {
            if ($add == PHP_EOL)
                $add = '<br>';
            return '<span style="color: green">' . $add_first . $string . $add . '</span>';
        }
    }

    private static function cyan($string, $add = PHP_EOL, $add_first = '· '): string {
        if (PHP_SAPI == 'cli')
            return "\033[" . "0;36m" . $add_first . $string . $add . "\033[0m";
        else {
            if ($add == PHP_EOL)
                $add = '<br>';
            return '<span style="color: cyan">' . $add_first . $string . $add . '</span>';
        }
    }

    private static function yellow($string, $add = PHP_EOL, $add_first = '· '): string {
        if (PHP_SAPI == 'cli')
            return "\033[" . "1;33m" . $add_first . $string . $add . "\033[0m";
        else {
            if ($add == PHP_EOL)
                $add = '<br>';
            return '<span style="color: yellow">' . $add_first . $string . $add . '</span>';
        }
    }

    private static function webServerOrCli() {
        if (PHP_SAPI == 'cli') {
            $text = self::green('Запущен через: ' . PHP_SAPI);
        } else if (isset($_SERVER['DOCUMENT_ROOT']) && isset($_SERVER['REQUEST_URI'])) {
            $text = self::green('Запущен через: ' . PHP_SAPI);
        } else {
            $text = self::red("Запущен через: Веб-сервер, но DOCUMENT_ROOT и REQUEST_URI не удалось получить");
        }
        return $text;
    }

    private static function checkImportantModules() {
        self::module('curl');
        self::module('json');
        self::module('mbstring');
        self::module('fileinfo');
        if (PHP_SAPI == 'cli') {
            self::module('pcntl');
            self::module('posix');
        }
    }

    private static function checkNoImportantModules() {
        self::module('mysqli');
        self::module('pdo_mysql');
        self::module('sqlite3');
        self::module('pdo_sqlite');
        self::module('pgsql');
        self::module('pdo_pgsql');
    }

    private static function module($name) {
        if (extension_loaded($name)) {
            self::$final_text .= self::green($name, ', ', '');
        } else {
            self::$final_text .= self::red($name, ', ', '');
        }
    }

    private static function testPingVK() {
        if (function_exists('curl_init')) {
            $arr = [];
            $ch = curl_init();
            for ($i = 0; $i < 15; $i++) {
                curl_setopt($ch, CURLOPT_URL, 'api.vk.com');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                $check = curl_exec($ch);
                if($check === false) {
                    return self::red("Не удалось выполнить сетевой запрос через curl, возможно проблемы с сетью");
                }
                $info = curl_getinfo($ch);
                if($i>5)
                    $arr[] = $info['total_time'];
            }
            curl_close($ch);
            $ms = round(array_sum($arr)/count($arr)*1000, 1);
            if($ms <= 40)
                return self::green("Пинг до api.vk.com: {$ms}мс");
            else if($ms > 40 && $ms < 100)
                return self::yellow("Пинг до api.vk.com: {$ms}мс");
            else
                return self::red("Пинг до api.vk.com: {$ms}мс");
        }
    }

    private static function get_memory($type = 1) {
        if ('WIN' == strtoupper(substr(PHP_OS, 0, 3)) && $type === 1) {
            @exec("wmic OS get TotalVisibleMemorySize" . " 2>&1", $s);
            $ram_max = isset($s[1]) ? round(((int)$s[1])/1024/1024, 2) : 0;

            @exec('wmic OS get FreePhysicalMemory /Value 2>&1', $output, $return);
            $ram_free = substr($output[2],19);
            $ram_free = round($ram_free/1024/1024,2);

            if($ram_max && $ram_free)
                return self::green("ОЗУ занято: $ram_free / $ram_max GB");
            else
                return self::yellow("Не удалось получить информацию об ОЗУ");
        } else {
            $meminfo_text = @file_get_contents("/proc/meminfo");
            if($meminfo_text !== false) {
                $data = explode("\n", $meminfo_text);
                $meminfo = [];
                foreach ($data as $line) {
                    $ex = @explode(":", $line);
                    $key = $ex[0] ?? null;
                    $val = $ex[1] ?? null;
                    $val = explode(' ',trim($val))[0] ?? null;
                    if($val)
                        $meminfo[$key] = $val;
                }
            }
            $ram_max = $meminfo['MemTotal'] ?? null;
            $ram_buffers = $meminfo['Buffers'] ?? null;
            $ram_cached = $meminfo['Cached'] ?? null;
            $ram_active = $meminfo['Active'] ?? null;

            if($ram_max && $ram_buffers && $ram_cached && $ram_active) {
                $ram_free = round(($ram_buffers+$ram_active)/1024/1024,2);
                $ram_free2 = round(($ram_buffers+$ram_active+$ram_cached)/1024/1024,2);
                $ram_max = round($ram_max/1024/1024,2);
                if($type === 1)
                    return self::green("ОЗУ занято: $ram_free / $ram_max GB (".(round($ram_free / $ram_max * 100))."%) (без учета cached)");
                else
                    return self::green("ОЗУ занято: $ram_free2 / $ram_max GB (".(round($ram_free2 / $ram_max * 100))."%) (с учетом cached)");
            } else
                return self::yellow("Не удалось получить информацию об ОЗУ");
        }
    }

    private static function num_cpus() {
        $numCpus = 0;

        if ('WIN' == strtoupper(substr(PHP_OS, 0, 3))) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');

            if (false !== $process) {
                @fgets($process);
                $numCpus = intval(@fgets($process));

                @pclose($process);
            }
        } else {
            if (@is_file('/proc/cpuinfo')) {
                $cpuinfo = @file_get_contents('/proc/cpuinfo');
                preg_match_all('/^processor/m', $cpuinfo, $matches);

                $numCpus = count($matches[0]);
            } else {
                $process = @popen('sysctl -a', 'rb');

                if (false !== $process) {
                    $output = @stream_get_contents($process);

                    preg_match('/hw.ncpu: (\d+)/', $output, $matches);
                    if ($matches) {
                        $numCpus = intval($matches[1][0]);
                    }

                    @pclose($process);
                }
            }
        }

        if($numCpus != 0)
            return self::green("Количество ядер процессора: ".$numCpus);
        else
            return self::yellow("Не удалось получить количество ядер процессора");
    }
}