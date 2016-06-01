<?php

/**
 * @author  ArtMares (Dmitriy Dergachev)
 * @date    06.03.16
 * @version 0.1
 */

/**
 * Class Loader
 * Класс Загрузчик
 * Инициализирует отображение окна загрузки компанентов с прогрессом выполнения
 * Класс имеет один обязательный аргумент и четыре не обязательных аргумента
 *
 *
 * Обязательно наличие подключенных аддонов Storage и Style
 * Обязательно наличие расширения PQEngine File System
 *
 * Если в темах оформления нет файла Loader.qss то будут применены стили по умолчанию для загрузчика
 *
 * Для корректной работы Loader необходимо наличие файла components.json в директории system корневой директории приложения
 * Файл components.json должен выглядеть так
 * [
    {
        "name"      : "Имя которое будет отображаться при выполнении загрузки",
        "class"     : "ClassName",
        "path"      : "Путь к файлу от корневой директории приложения или ресурсов",
        "init"      : true or false - Параметр, который отвечает за инициализацию компанента при загрузке,
        "resource"  : true or false - Параметр, который отчевает за загрузку копанента из русерсов или нет
    }
 * ]
 *
 * Пример:
 * [
    {
        "name"      : "Controllers",
        "class"     : "MainController",
        "path"      : "Controllers/",
        "init"      : true,
        "resource"  : false
    },
    {
        "name"      : "UI Elements",
        "class"     : "MyToolBtn",
        "path"      : "ui/",
        "init"      : false,
        "resource:  : true
    }
 * ]
 *
 * Параметр "name" - Название которое будет отображаться при загрузке компанента
 * Параметр "class" - Имя класса для инициализации, должно совпадать с названием класса
 * Параметр "path" - Путь к файлу на конце обязательно должен иметь слешь
 * Параметр "init" - Отвечает за инициализацию класса
 * Параметр "resource" - Отвечает за загрузку компанента из ресурсов приложения
 *
 * Пути к файлам из примера:
 * Если параметр "resource" установлен в true то компонент будет загружен из ресурсов приложения
 * /res/ui/MyToolBtn.php
 *
 * В противном случаем из директории приложения
 * %APP_PATH%/Controllers/MainController.php
 *
 * Все компаненты у которых параметр "init" установлен в true будут инициализированы и переданы
 * в Storage как свойства c названием из параметра "class"
 * В дальнейшем получить эти классы можно так $controller = $this->storage->ClassName
 * Все классы которые были не инициализированны при загрузке можно инициализировать в любой момент
 * стандартным объявлением класса $class = new ClassName();
 *
 */
class Loader extends QFrame {
    /** Задаем сигналы */
    private $signals = [
        'next()',
        'completed()'
    ];
    /** Хранилище */
    private $storage;
    /** Путь к корневому каталогу приложения */
    private $dir;
    /** Дочерний каталог приложения в котором нахождится файл конфигурации */
    private $path;
    /** Свойство отвечающее за загрузку копмнонентов из ресурсов приложения */
    private $resource = false;
    /** Прогресс Бар */
    private $progress;
    /** QLabel для отображения текста */
    private $message;
    /** Поток */
    private $thread;
    /** Worker потока */
    private $worker;

    /**
     * Loader constructor.
     * @param string $path - Дочерняя диреткория приложения в которой расположен файл Loader.json
     * @param bool $resource - Параметр отвечает за нахождение файла конфигурации в ресурсах или нет
     * @param string $image - Путь к изображению для фоновой завтавки
     * @param int $imageWidth - Ширина фонового изображения
     * @param int $imageHeight - Высота фонового изображения
     */
    public function __construct($path, $resource = false, $image = '', $imageWidth = 512, $imageHeight = 512) {
        /** Делаем проверку на тип аргумента */
        if(is_string($path)) {
            /** Если аргумент является строкой то проверяем не пуст ли он */
            if(!empty($path)) {
                /** Вызываем метод который проверяет наличие необходимых зависимостей */
                $this->checkDepend();
                /** Если не пуст инциализируем родительский класс QFrame */
                parent::__construct();
                /** Получаем путь к корневой директории приложения */
                $this->dir = qApp::applicationDirPath().'/';
                /** Запоминаем дочерний каталог */
                $this->path = $path;
                /** Присваиваем свойству значение */
                $this->resource = $resource;
                /** Задаем флаг и атрибут для прозрачного фона QFrame */
                $this->setWindowFlags(Qt::FramelessWindowHint);
                $this->setAttribute(Qt::WA_TranslucentBackground);
                /** Убираем автоматическую заливку фона для QFrame */
                $this->setAutoFillBackground(false);
                /** Задаем стиль для окна */
                $style = Style::get(__CLASS__);
                /** Проверяем существует ли пользовательский стиль для класса Loader */
                if(!empty($style)) {
                    /** Если существует то задаем пользовательский стиль */
                    $this->styleSheet = $style;
                } else {
                    /** В противном случае задаем стиль по умолчанию */
                    $this->styleSheet = '
                        QLabel {
                            color: #323232;
                            font-size: 12px;
                        }
                        QLabel#Message {
                            padding-left: 5px;
                            background: #cfcfcf;
                            border-top-left-radius: 6px;
                            border-top-right-radius: 6px;
                        }
                        QProgressBar {
                            background: #cfcfcf;
                            height: 10px;
                            border-bottom-left-radius: 6px;
                            border-bottom-right-radius: 6px;
                        }
                        QProgressBar::chunk {
                            background-color: #276ccc;
                            border-bottom-left-radius: 6px;
                            border-bottom-right-radius: 6px;
                        }
                    ';
                }
                /** Создаем слой */
                $this->layout = new QVBoxLayout;
                /** Задаем отступы у слоя */
                $this->layout->setMargin(0);
                $this->layout->setSpacing(0);

                /** Проверяем был ли передан путь для фонового изображения */
                if(!empty($image)) {
                    /** Создаем QLabel в который вставим логотип */
                    $logo = new QLabel($this);
                    /** Вставляем фоновое изображение размером заданым при инициализации */
                    $logo->setPixmap($image, $imageWidth, $imageHeight);
                    /** Вставляем QLabel на слой и задаем позиционирование по центру */
                    $this->layout->addWidget($logo);
                    $this->layout->setAlignment($logo, Qt::AlignCenter);
                } else {
                    $imageWidth = $imageHeight = 0;
                }

                /** Создаем QLabel в котором будет отображаться сообщение о загрузке компанентов */
                $this->message = new QLabel($this);
                $this->message->objectName = 'Message';
                $this->message->text = tr('Loading components');
                /** Добавляем QLabel на слой */
                $this->layout->addWidget($this->message);

                /** Создаем QProgressBar для отображения прогресса загрузки всех компанентов */
                $this->progress = new QProgressBar($this);
                /** Задаем минимальное значение */
                $this->progress->setMinimum(0);
                /** Задаем текущее значение */
                $this->progress->setValue(0);
                /** Убираем отображение текста */
                $this->progress->textVisible = false;
                /** Добавляем QProgressBar на слой */
                $this->layout->addWidget($this->progress);

                /**
                 * Задаем минимальную и максимальную ширину окна
                 * Если ширина изображения больше минимальной то применяется ширина изображения
                 */
                $this->setMaximumWidth(($imageWidth >= 400 ? $imageWidth : 400));
                $this->setMinimumWidth(($imageWidth >= 400 ? $imageWidth : 400));

                /** Отображаем QFrame */
                $this->show();
            } else {
                /** Если аргумент является пустой строкой то выводи сообщение о ошибке и завершаем работу прилоожения */
                die("Error!\r\nIt is necessary to specify a child directory!");
            }
        } else {
            /** Если аргумент не является строкой то выводим сообщение о ошибке и завершаем работу приложения */
            die("Error!\r\nThe argument shall be string type!");
        }
    }

    /**
     * Метод checkDepend() - Проверяет наличие зависимостей для корректной работы аддона
     */
    private function checkDepend() {
        /** Проверяем наличие подключенного аддона Storage */
        if(class_exists('Storage')) {
            /** Загружаем хранилище если аддон подключен */
            $this->storage = loadStorage();
        } else {
            /** Выводим сообщение о ошибке и завершаем работу приложения */
            die("Error!\r\nNeed to connect Add-on \"Storage\"!");
        }
        /** Проверяем наличие подключенного фддона Style */
        if(!class_exists('Style')) {
            /** Если аддон не подключен то выводим сообщение о ошибке и завершаем работу приложения */
            die("Error!\r\nNeed to connect Add-on \"Style\"!");
        }
        /** Проверяем наличие подключено расширения PQEngine File System */
        if(!class_exists('QDir') && !class_exists('QFile')) {
            /** Выводим сообщение о ошибке и завершаем работу приложения */
            die("Error!\r\nNeed to build the project with extension \"PQEngine File System\"!");
        }
    }

    /**
     * Метод start() - Запускает фоновою загрузку компанентов
     */
    public function start() {
        /** Проверяем от куда необходимо загружать файл конфигурации */
        if($this->resource === true) {
            /** Получаем данные из файла конфигурации находящего в ресурсах приложения */
            $components = json_decode(file_get_contents("qrc://$this->path/Loader.json"), true);
        } else {
            /** Получаем данные из файла конфигруации находящегося в директории приложения */
            $file = new QFile($this->dir.$this->path.'/Loader.json');
            $file->open(QFile::ReadOnly);
            $components = json_decode($file->readAll(), true);
        }
        /** Проверяем есть ли данные о компанентах */
        if(!empty($components)) {
            /** Задаем максимальное значние для QProgressBar по количеству компанентов */
            $this->progress->setMaximum(count($components));
            /** Инициализируем поток */
            $this->thread = new QThread();
            /** Инициализируем Класс Worker */
            $this->worker = new LoaderWorker($components);
            /** Перемещаем Класс Worker в поток */
            $this->worker->moveToThread($this->thread);
            /** Соединяем сигналы Класса Worker */
            $this->worker->connect(SIGNAL('load(int,string,string,string,bool,bool)'), $this, SLOT('loading(int,string,string,string,bool,bool)'));
            $this->worker->connect(SIGNAL('done()'), $this, SLOT('completed()'));
            /** Соединяем сигнал с Классом Worker */
            $this->connect(SIGNAL('next()'), $this->worker, SLOT('next()'));
            /** Запускаем поток */
            $this->thread->start();
            /** Передаем сигнал в поток */
            $this->emit('next()', []);
        } else {
            /** Если данных нет то выводи сообщение о шибке и завершаем работу приложения */
            die("Error!\r\nIt isn't possible to read data from the file of a configuration \"Loader.json\"");
        }
    }

    /**
     * Метод loading() - Подключает компанент к в приложение и инициализирует его если необходимо
     * @param $sender - Обязательный параметр необходимый для корректной работы connect()
     * @param $int - Порядковый номер компанента
     * @param $path - Путь к компаненту
     * @param $class - Название класса, который необходимо инициализировать
     * @param $name - Название компанента для отображения в прогрессе загрузки
     * @param $init - Параметр отвечающий за необходимость инциализации компанента
     * @param $resource - Параметр отвечающий за загрузку из ресурсов приложения
     */
    public function loading($sender, $int, $path, $class, $name, $init, $resource) {
        /** Задаем текст для отображения состояния */
        $this->message->text = tr('Loading component')." : ".$name;
        /** Подключаем файл с компанентом */
        require_once ($resource ? 'qrc://'.$path : $this->dir.$path);
        /** Проверяем существует ли класс */
        if(class_exists($class)) {
            /** Если класс существует то инциализируем класс и передаем его в Хранилище */
            if($init) $this->storage->$class = new $class();
            /** Изменияем текущее значение QProgressBar */
            $this->progress->setValue($int);
            /** Делаем задержку */
            usleep(10000);
            /** Передаем сигнал в поток */
            $this->emit('next()', []);
        }
    }

    /**
     * Метод completed() - Вызывается по окончании загрузки и передает сигнал об окончании загрузки родителю
     * @param $sender - Обязательный параметр необходимый для корректной работы connect()
     */
    public function completed($sender) {
        /** Остнавливаем поток так как он больше не нужен */
        $this->thread->stop();
        /** Создаем искуственное ожидание в 2 секунды для коректного отображения */
        sleep(2);
        /** Закрываем окно Загрузчика */
        $this->close();
        /** Передаем сигнал о завершении загрузки компанентов */
        $this->emit('completed()', []);
    }
}


/**
 * Class LoaderWorker
 * Класс Работник исползуется для того чтобы избежать подвисания приложения во время загрузки компанентов
 */
class LoaderWorker extends QObject {
    /** Задаем сигналы */
    private $signals = [
        'load(int,string,string,string,bool,bool)',
        'done()'
    ];
    /** Список компанентов */
    private $components;
    /** Номер текущего компанента */
    private $now;
    /** Количество компанентов */
    private $count;

    /**
     * LoaderWorker constructor.
     * @param $components - Массив компанентов которые необходимо загрузить
     */
    public function __construct($components) {
        parent::__construct();
        /** Запонминаем список компанентов */
        $this->components = $components;
        /** Задаем номер текущего компанента для загрузки */
        $this->now = 0;
        /** Получаем количество компанентов */
        $this->count = count($components);
    }

    /**
     * Метод next() - основной метод, который отправляет родителю данные о компаненте для загрузки
     * @param $sender - Обязательный параметр необходимый для корректной работы connect()
     */
    public function next($sender) {
        /** Если компанент первый то делаем искуственную задержку в секунду */
        if($this->now == 0) sleep(1);
        /** Проверяем что компанент сущетсвует и номер текущего меньше или равен количеству компанентов */
        if(isset($this->components[$this->now]) && $this->now <= $this->count) {
            /** Передаем во временную переменную данные о текущем компаненте */
            $data = $this->components[$this->now];
            /** Увеличиваем номер текущего компанента */
            $this->now++;
            /** Передаем сигнал на загрузку компанента и данные о компаненте */
            $this->emit('load(int,string,string,string,bool,bool)', [
                $this->now,
                $data['path'].$data['class'].'.php',
                $data['class'],
                $data['name'],
                $data['init'],
                $data['resource']
            ]);
        } else {
            /**
             * Если компанента не существует в списке и номер текущего больше количества компанентов
             * то передаем сигнал о завершении загрузки компанентов
             */
            $this->emit('done()', []);
        }
    }
}