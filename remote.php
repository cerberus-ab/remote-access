<?php

    /**
     * Базовый класс удаленного доступа
     * @note: используется расширение ssh2_lib
     */
    class RemoteAccess {

        // дискриптор сессии
        private $session;

        // текст последней ошибки
        private $last_error = null;

        // настройки соединения
        private $options = array(
            // адрес удаленной машины
            'host' => 'localhost',
            // используемый порт
            'port' => 22,
            // логин для входа
            'user' => 'root',
            // пароль для входа
            'pass' => '',
            // название дискриптора соединения
            'name' => null
        );

        /**
         * Статический метод пинга удаленной машины
         * @param  [string] $host адрес устройства
         * @param  [integer] $count количество запросов
         * @param  [integer] $wait время ожидания (с)
         * @return [string] результат работы команды
         */
        public static function ping($host, $count = 1, $wait = 1) {
            exec('ping '.$host.' -c '.$count.' -w '.$wait, $result);
            preg_match('/min\/avg\/max\/mdev = (.*)\/(.*)\/(.*)\/(.*) ms/', implode('', $result), $matches);
            return !count($matches) ? FALSE : array(
                'min' => (double)$matches[1],
                'avg' => (double)$matches[2],
                'max' => (double)$matches[3],
                'mdev' => (double)$matches[4],
                'units' => 'ms'
            );
        }

        /**
         * Функция вывода ошибки
         * @param  [string] $err - текст ошибки
         * @return [exception] исключение
         */
        protected function setError($err) {
            $this->last_error = (!is_null($this->options['name']) ? $this->options['name'].': ' :'').$err;
        }

        /**
         * Конструктор установления соединения
         * @param [array] $options - параметры подключения
         */
        function __construct($options = array()) {
            // настройки по умолчанию
            $this->options = array_merge($this->options, $options);
            // попытка установить соединение
            if (!$this->session = ssh2_connect($this->options['host'], $this->options['port'])) {
                $this->setError('Не удалось установить соединение с удаленной машиной!');
            }
            // попытка авторизоваться
            if (!ssh2_auth_password($this->session, $this->options['user'], $this->options['pass'])) {
                $this->setError('Не удалось авторизоваться на удаленной машине!');
            }
        }

        /**
         * Деструктор соединения
         */
        public function Destroy() {
            $this->session = null;
        }

        /**
         * Получить текст последней ошибки
         * @return [string] текст ошибки или NULL
         */
        public function getLastError() {
            return $this->last_error;
        }

        /**
         * Выполнить команду на удаленной машине
         * @param  [string] $command - команда
         * @param  [boolean] inarray - представить результат как массив строк
         * @return [mix] консольный вывод или FALSE
         */
        public function exec($command, $inarray = false) {
            try {
                $stream = ssh2_exec($this->session, $command);
                stream_set_blocking($stream, true);
                $stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
                $result = stream_get_contents($stream_out);
                return $inarray ? explode("\n", $result) : $result;
            }
            catch (Exception $e) {
                $this->setError('Не удалось выполнить команду на удаленной машине!');
                return FALSE;
            }
        }

        /**
         * Выполнить команду и вернуть дискриптор потока
         * @param  [string] $command команда
         * @return [resourse] декскриптор потока
         */
        public function exec_run($command) {
            return ssh2_exec($this->session, $command);
        }
    }

    /**
     * Класс выполнения базовых команд на удаленной *nix машине
     */
    class RemoteExec extends RemoteAccess {

        /**
         * Проверка файла
         * @param  [string] $file путь к файлу
         * @param  [string] $mode режим
         * @return [boolean] успешность проверки
         */
        private function filetest($file, $mode) {
            $command = 'if test -'.$mode.' '.$file.'; then echo YES; fi';
            return strlen($file) && strpos($this->exec($command), 'YES') !== FALSE;
        }

        /**
         * Проверка существования файла
         * @param  [string] $file - путь к файлу
         * @return [boolean] является ли файлом
         */
        public function isfile($file) {
            return $this->filetest($file, 'f');
        }

        /**
         * Проверка файла на выполняемость
         * @param  [string] $file - путь к файлу
         * @return [boolean] является ли исполняемым
         */
        public function isexecutable($file) {
            return $this->filetest($file, 'x');
        }

        /**
         * Проверка файла на возможность чтения
         * @param  [string] $file путь к файлу
         * @return [boolean] возможно чтение
         */
        public function isreadable($file) {
            return $this->filetest($file, 'r');
        }

        /**
         * Проверка файла на возможность записи
         * @param  [string] $file путь к файлу
         * @return [boolean] возможна запись
         */
        public function iswritable($file) {
            return $this->filetest($file, 'w');
        }

        /**
         * Получить содержимое файла
         * @param  [string] $file путь к файлу
         * @param  [string] $grep фильтр строк файла
         * @return [string] содержимое файла или FALSE
         */
        public function catfile($file, $grep = false) {
            try {
                // проверка наличия файла и возможности его чтения
                if (!$this->isreadable($file)) {
                    throw new Exception('Файл \''.$file.'\' не найден или нет прав на его чтение!');
                }
                // прочитать файл и вернуть его содержимое
                return $this->exec('cat '.$file.($grep ? ' | '.$grep : ''));
            }
            catch (Exception $e) {
                // вернуть FALSE с сохр текста ошибки
                $this->setError($e->getMessage());
                return FALSE;
            }
        }

        /**
         * Получить конфиг оборудования
         * @return [string] текст конфига
         */
        public function ifconfig() {
            return $this->exec('/sbin/ifconfig -a');
        }

        /**
         * Получить значение пропускной способности на сетевом интерфейсе
         * @param  [string] $interface название интерфейса
         * @param  [Array] $use набор используемых утилит в порядке обращения
         * @return [float] скорость в Mbps или FALSE в случае ошибки
         */
        public function ethspeed($interface, $use = array('dmesg', 'ethtool')) {
            $speed = NULL;
            // обход списка используемых утилит
            foreach ($use as $tool) {
                switch ($tool) {
                    // утилита dmesg
                    case 'dmesg':
                        $result = $this->exec('dmesg | grep '.$interface.' | grep Mbps');
                        preg_match_all('/Link is up at (\S*) Mbps/', $result, $matches);
                        $speed = $matches[1][0];
                        break;

                    // утилита ethtool
                    case 'ethtool':
                        $result = $this->exec('ethtool '.$interface.' | grep Speed:');
                        preg_match_all('/Speed: (\S*)Mb\/s/', $result, $matches);
                        $speed = $matches[1][0];
                        break;

                    // неизвестная утилита
                    default:
                        break;
                }
                // проверка speed на этой итерации
                if (is_numeric($speed)) {
                    return (float)$speed;
                }
            }
            // не удалось определить скорость
            return FALSE;
        }

        /**
         * Проверка доступности удаленного хоста (ping)
         * @param  [string]  $host - ip адрес удаленного хоста
         * @param  [integer] $count - количество icmp запросов
         * @return [boolean] доступен или нет
         */
        public function isping($host, $count = 1) {
            $result = $this->exec('ping '.$host.' -c '.$count.' -w 1');
            return $result && strpos($result, '0 received') === FALSE;
        }

        /**
         * Получить название удаленного хоста
         * @return [string] мнемоническое имя хоста
         */
        public function hostname() {
            return trim($this->exec('hostname'));
        }
    }

?>
