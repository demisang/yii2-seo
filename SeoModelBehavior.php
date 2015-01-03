<?php
/**
 * Поведение для работы с SEO параметрами
 */

namespace demi\seo;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\validators\FilterValidator;
use yii\validators\UniqueValidator;
use yii\validators\Validator;
use yii\widgets\ActiveForm;

/**
 * Class SeoModelBehavior
 * @package common\components\behaviors
 *
 * @property ActiveRecord $owner
 */
class SeoModelBehavior extends Behavior
{
    /** @var string Название поля, отвечающего за SEO:url */
    private $_urlField;
    /** @var string|callable Название поля из которого будет формироваться SEO:url, либо функция,
     * которая вернёт текст, который будет обработан для генерации SEO:url */
    private $_urlProduceField = 'title';
    /** @var string|callable PHP-выражение, которое формирует поле SEO:title
     * Пример: function($model, $lang) { return $model->{"title_".$lang} } */
    private $_titleProduceFunc;
    /** @var string|callable PHP-выражение, которое формирует поле SEO:desciption */
    private $_descriptionProduceFunc;
    /** @var string|callable PHP-выражение, которое формирует поле SEO:keywords */
    private $_keysProduceFunc;
    /**
     * @var string Название результирующего поля, куда будут сохранены
     * в сериализованном виде все SEO-параметры */
    private $_metaField;
    /** @var boolean|callable Позволено ли пользователю менять SEO-данные */
    private $_clientChange = false;
    /** @var integer Максимальная длинна поля SEO: url */
    private $_maxUrlLength = 70;
    /** @var integer Максимальная длинна поля Title */
    private $_maxTitleLength = 70;
    /** @var integer Максимальная длинна поля Description */
    private $_maxDescLength = 130;
    /** @var integer Максимальная длинна поля Keywords */
    private $_maxKeysLength = 150;
    /** @var array Запрещённые для использования SEO:url адреса */
    private $_stopNames = [];
    /** @var string маршрут для создания SEO ссылки, например "post/view" */
    private $_viewRoute = '';
    /** @var string Название параметра, в котором будет передан SEO:url во создания ссылки
     * Вот пример места, где используется этот параметр: Url::to(["route", linkTitleParamName=>seo_url]) */
    private $_linkTitleParamName = 'title';
    /** @var array|callable дополнительные параметры SEO:url для создания ссылки */
    private $_additionalLinkParams = [];
    /** @var array Список языков, на которых должны быть доступны SEO-опции */
    private $_languages = [];
    /** @var string Название класса-контроллера, с которым работает текущая модель.
     * Необходимо указывать для получения списка экшенов контроллера, чтобы
     * seo_url не совпадал с существующими экшенами в контроллере */
    private $_controllerClassName = '';
    /** @var boolean Необходимо ли использовать только нижний регистр при генерации seo_url */
    private $_toLowerSeoUrl = true;
    /** @var Query Дополнительные критерии при проверки seo_url на уникальность */
    private $_uniqueUrlFilter;
    /** @var string Регулярное выражение для проверки того, что запрос идёт в обход seo_url.
     * Искомая строка - Yii::app()->request->pathInfo
     * Если запрос прошёл в обход, тогда будет redirect на правильный viewUrl */
    private $_checkSeoUrlRegexp = '';
    /** @var string Кодировка сайта */
    private $_encoding = 'UTF-8';
    /**
     * @var array Конфигурационный массив, который переопределяет вышеуказанные настройки
     */
    public $seoConfig = [];
    const TITLE_KEY = 'title';
    const DESC_KEY = 'desc';
    const KEYS_KEY = 'keys';

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    public function attach($owner)
    {
        parent::attach($owner);

        // Применяем конфигурационные опции
        foreach ($this->seoConfig as $key => $value) {
            $var = '_' . $key;
            $this->$var = $value;
        }

        $this->_languages = (array)$this->_languages;
        // Если не было передано ни одного языка - будем использовать только один системный язык
        if (!count($this->_languages)) {
            $this->_languages = [Yii::$app->language];
        }
        // Проверка на то, может ли текущий пользователь видеть и редактировать SEO-данные модели
        if (is_callable($this->_clientChange)) {
            $this->_clientChange = call_user_func($this->_clientChange, $owner);
        }

        // Если маршрут для создания ссылки на просмотр модели не указан - генерируем
        if (empty($this->_viewRoute)) {
            $this->_viewRoute = strtolower(basename(get_class($owner))) . '/view';
        }

        // Определяем контроллер и заносим его экшены в стоп-лист
        if (class_exists($this->_controllerClassName, false)) {
            $reflection = new \ReflectionClass($this->_controllerClassName);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            $controller = $reflection->newInstance(null);
            // Добавляем все подключаемые экшены контроллера
            $buffer = array_keys($controller->actions());
            // Перебираем все основные экшены контроллера
            foreach ($methods as $method) {
                /* @var $method \ReflectionMethod */
                $name = $method->getName();
                if ($name !== 'actions' && substr($name, 0, 6) == 'action') {
                    $action = substr($name, 6, strlen($name));
                    $action[0] = strtolower($action[0]);
                    $buffer[] = $action;
                }
            }
            // Объединяем экшены контроллера с экшенами конфига поведения
            $this->_stopNames = array_merge($this->_stopNames, array_unique($buffer));
        }
    }

    public function beforeValidate()
    {
        $model = $this->owner;

        if (!empty($this->_metaField)) {
            // Перебираем все доступные языки, зачастую он всего один
            foreach ($this->_languages as $lang) {
                // Перебираем meta-поля и обрезаем длину meta-срок до установленных значений
                foreach ($this->getMetaFields() as $meta_param_key => $meta_param_value_generator) {
                    $this->applyMaxLength($meta_param_key, $lang);
                }
            }
        }

        if (empty($this->_urlField)) {
            // Если не нужна работа с SEO:url, то пропускаем дальнейшую работу
            return;
        }

        // Добавляем валидатор UNIQUE для SEO:url
        $validator = Validator::createValidator(UniqueValidator::className(), $model, $this->_urlField, [
            'filter' => $this->_uniqueUrlFilter,
        ]);

        // Если SEO:url ещё не заполнен пользователем, то сгенерируем его значение
        if (($urlFieldVal = $model->getAttribute($this->_urlField)) !== null) {
            $urlFieldVal = $this->getProduceFieldValue($this->_urlProduceField);
        }
        // Транслитерируем строку и убираем из неё лишние символы
        $seoUrl = $this->getSeoName($urlFieldVal, $this->_maxUrlLength, $this->_toLowerSeoUrl);

        // Если есть совпадение с запрещёнными именами, то к url добавляем нижнее подчёркивание в самый конец
        while (in_array($seoUrl, $this->_stopNames)) {
            $seoUrl .= '_';
        }

        $model->setAttribute($this->_urlField, $seoUrl);
        // Запускаем первую валидацию
        $validator->validateAttribute($model, $this->_urlField);

        // Запускаем валидацию до 50 раз, пока не будет найдено уникальное SEO:url имя
        $i = 0;
        while ($model->hasErrors($this->_urlField)) {
            // Убираем сообщение о ошибке, полученное в последней валидации
            $model->clearErrors($this->_urlField);

            // Если 50 раз "в молоко" ушла валидация, значит что-то пошло не так
            if (++$i > 50) {
                // Установим SEO:url равным случайному хешу
                $model->setAttribute($this->_urlField, md5(uniqid()));
                // Закончим "бесконечный" цикл
                break;
            }

            // Добавляем "_" в конец SEO:url
            $newSeoUrl = $model->getAttribute($this->_urlField) . '_';
            $model->setAttribute($this->_urlField, $newSeoUrl);
            // Запускаем валидатор ещё раз, ведь в предыдущей строке мы изменили значение, добавив постфикс
            $validator->validateAttribute($model, $this->_urlField);
        }
    }

    /**
     * Проверяет максимальную длину meta-строк, и если она превышает лимит - обрезает до максимального значения
     *
     * @param string $key
     * @param string $lang
     */
    private function applyMaxLength($key, $lang)
    {
        $value = trim($this->getMetaFieldVal($key, $lang));
        if ($key === self::TITLE_KEY) {
            $max = $this->_maxTitleLength;
        } elseif ($key === self::DESC_KEY) {
            $max = $this->_maxDescLength;
        } else {
            $max = $this->_maxKeysLength;
        }

        if (mb_strlen($value, $this->_encoding) > $max) {
            $value = mb_substr($value, 0, $max, $this->_encoding);
        }

        $this->setMetaFieldVal($key, $lang, $value);
    }

    public function beforeSave()
    {
        $model = $this->owner;

        if (empty($this->_metaField)) {
            // Если не указано meta-поле, то мы его не будем сохранять
            return;
        }

        // Проверяем все SEO поля и заполняем их данными: если указаны пользователем - оставляем как есть, если
        // отсутствует - генерируем
        $this->fillMeta();

        $meta = $model->getAttribute($this->_metaField);

        // Сохраняем все наши параметры в сериализованном виде
        $model->setAttribute($this->_metaField, serialize($meta));
    }

    /**
     * Проверяет заполненность всех SEO:meta полей. При их отсутствии они будут сгенерированы.
     */
    private function fillMeta()
    {
        // Перебираем все доступные языки, зачастую он всего один
        foreach ($this->_languages as $lang) {
            // Перебираем meta-поля и заполняем их при отсутствии заполненных данных
            foreach ($this->getMetaFields() as $meta_params_key => $meta_param_value_generator) {
                $meta_params_val = $this->getMetaFieldVal($meta_params_key, $lang);
                if (empty($meta_params_val) && $meta_param_value_generator !== null) {
                    // Получаем значение из генератора
                    $meta_params_val = $this->getProduceFieldValue($meta_param_value_generator, $lang);
                    $this->setMetaFieldVal($meta_params_key, $lang, $this->normalizeStr($meta_params_val));
                }
                // Проверим, что длина строки в норме
                $this->applyMaxLength($meta_params_key, $lang);
            }
        }
    }

    public function afterFind()
    {
        $model = $this->owner;

        if (!empty($this->_metaField)) {
            // Распаковываем meta-параметры
            $meta = @unserialize($model->getAttribute($this->_metaField));
            if (!is_array($meta)) {
                $meta = [];
            }

            $model->setAttribute($this->_metaField, $meta);
        }
    }

    /**
     * Возвращает массив meta-полей. В качестве значения идёт callback функция
     *
     * @return callable[]
     */
    private function getMetaFields()
    {
        return [
            static::TITLE_KEY => $this->_titleProduceFunc,
            static::DESC_KEY => $this->_descriptionProduceFunc,
            static::KEYS_KEY => $this->_keysProduceFunc,
        ];
    }

    /**
     * Возвращает значение $key из SEO:meta для указанного $lang языка
     *
     * @param string $key  ключ TITLE_KEY, DESC_KEY или KEYS_KEY
     * @param string $lang язык
     *
     * @return string|null
     */
    private function getMetaFieldVal($key, $lang)
    {
        $param = $key . '_' . $lang;
        $meta = $this->owner->getAttribute($this->_metaField);

        return is_array($meta) && isset($meta[$param]) ? $meta[$param] : null;
    }

    /**
     * Устанавливает значение $key в SEO:meta на указанном $lang языке
     *
     * @param string $key   ключ TITLE_KEY, DESC_KEY или KEYS_KEY
     * @param string $lang  язык
     * @param string $value значение
     */
    private function setMetaFieldVal($key, $lang, $value)
    {
        $model = $this->owner;
        $param = $key . '_' . $lang;
        $meta = $model->getAttribute($this->_metaField);
        if (!is_array($meta)) {
            $meta = [];
        }

        $meta[$param] = (string)$value;

        $model->setAttribute($this->_metaField, $meta);
    }

    /**
     * Возвращает метаданные этой модели
     *
     * @param string|null $lang язык, для которого требуются meta-данные
     *
     * @return array
     */
    public function getSeoData($lang = null)
    {
        if (empty($lang)) {
            $lang = Yii::$app->language;
        }

        $buffer = [];

        if (!empty($this->_metaField)) {
            // Проверяем, чтобы все meta-поля были заполнены значениями
            $this->fillMeta();
        }

        // Если meta хранится в model, то вернём значение из модели, иначе будем генерировать на лету
        $getValMethodName = !empty($this->_metaField) ? 'getMetaFieldVal' : 'getProduceFieldValue';

        foreach ($this->getMetaFields() as $meta_params_key => $meta_param_value_generator) {
            // Выбираем какой параметр передадим в функцию получения значения: название поля или функцию-генератор
            $getValMethodParam = !empty($this->_metaField) ? $meta_params_key : $meta_param_value_generator;
            // Непосредственное получение значения, либо из meta-поля модели, либо сгенерированноеы
            $buffer[$meta_params_key] = $this->$getValMethodName($getValMethodParam, $lang);
        }


        return $buffer;
    }

    /**
     * Возвращаем этот экземпляр behavior`а
     *
     * @return SeoModelBehavior $this
     */
    public function getSeoBehavior()
    {
        return $this;
    }

    /**
     * Нормализует строку, подготоваливает её для записи в БД
     *
     * @param string $str
     *
     * @return string
     */
    private function normalizeStr($str)
    {
        // Заменяем все пробелы, переносы строк и табы на один пробел
        $str = preg_replace('/[\s]+/iu', ' ', $str);
        $str = trim(strip_tags($str));

        return $str;
    }

    /**
     * Вовзвращает URL на страницу просмотра модели-владельца
     *
     * @param string $title  SEO:title для url, использовать только если ссылка генерируется не из модели, а произвольно
     * @param string $anchor #Якорь, который добвится к url, а $title можно указать как NULL
     * @param boolean $abs   Является ли эта ссылка абсолютной, а $title и $anchor можно указать как NULL
     *
     * @return string
     */
    public function getViewUrl($title = null, $anchor = null, $abs = false)
    {
        // Если дополнительные параметры ссылки должны генерироваться - делаем генерацию
        if (is_callable($this->_additionalLinkParams)) {
            $this->_additionalLinkParams = call_user_func($this->_additionalLinkParams, $this->owner);
        }

        // Если модель не имеет SEO:url, то в качестве значения этого поля будет использоваться ID модели
        if (empty($this->_urlField) && empty($title)) {
            $title = $this->owner->getPrimaryKey();
        }

        // Добавляем параметр, который отвечает за отображение SEO:url
        $params = [$this->_linkTitleParamName => !empty($title) ? $title : $this->owner->getAttribute($this->_urlField)];

        // Добавляем якорь
        if (!empty($anchor)) {
            $params['#'] = $anchor;
        }

        return Url::to(array_merge([$this->_viewRoute], array_merge($params, $this->_additionalLinkParams)), $abs);
    }

    /**
     * Вовзвращает абсолютный URL на страницу просмотра модели-владельца
     *
     * @param string $title  SEO:title для url, использовать только если ссылка генерируется не из модели, а произвольно
     * @param string $anchor Якорь, который добвится к url, а $title можно указать как NULL
     *
     * @return string
     */
    public function getAbsoluteViewUrl($title = null, $anchor = null)
    {
        return $this->getViewUrl($title, $anchor, true);
    }

    /**
     * Рендерим SEO поля для формы
     *
     * @param ActiveForm $form
     */
    public function renderFormSeoFields($form = null)
    {
        // Если параметры нельзя редактировать вручную - значит нечего отображать их в форме
        if (!$this->_clientChange) {
            return;
        }

        $model = $this->owner;

        echo '<hr />';

        if ($form instanceof ActiveForm) {
            $form->field($model, $this->_urlField);
        } else {
            echo '<div class="seo_row">';
            echo Html::activeLabel($model, $this->_urlField);
            echo Html::activeTextInput($model, $this->_urlField);
            echo Html::error($model, $this->_urlField);
            echo "</div>\n\n";
        }

        foreach ($this->_languages as $lang) {
            foreach ($this->getMetaFields() as $meta_field_key => $meta_field_generator) {
                $attr = $this->_metaField . "[{$meta_field_key}_{$lang}]";
                if ($form instanceof ActiveForm) {
                    $input = $meta_field_key == self::DESC_KEY ? 'textarea' : 'field';
                    echo $form->$input($model, $attr);
                } else {
                    $input = $meta_field_key == self::DESC_KEY ? 'activeTextarea' : 'activeTextInput';
                    echo '<div class="seo_row">';
                    echo Html::activeLabel($model, $attr);
                    echo Html::$input($model, $attr);
                    echo Html::error($model, $attr);
                    echo "</div>\n";
                }
            }
        }
    }

    /**
     * Возвращает сгенерированное значение для meta-полей
     *
     * @param callable|string $produceFunc
     * @param string $lang
     *
     * @return string
     */
    private function getProduceFieldValue($produceFunc, $lang = null)
    {
        $model = $this->owner;
        if (is_callable($produceFunc)) {
            return (string)call_user_func($produceFunc, $model, $lang);
        } else {
            return (string)$model->getAttribute($produceFunc);
        }
    }

    /**
     * Проверяет, чтобы только по seo_url осуществлялся просмотр данной модели
     * @return boolean true если всё хорошо, иначе происходит редирект 301 на правильный URL
     */
    public function checkSeoUrl()
    {
        if (!empty($this->check_seo_url_regexp) && preg_match($this->_checkSeoUrlRegexp, Yii::$app->request->pathInfo)
        ) {
            Yii::$app->response->redirect($this->getViewUrl(), 301);
        }

        return true;
    }

    /**
     * Возвращает пригодное для использования SEO имя
     *
     * @param string $title
     * @param int $maxLength
     * @param bool $to_lower
     *
     * @return string
     */
    private function getSeoName($title, $maxLength = 255, $to_lower = true)
    {
        $trans = array(
            "а" => "a", "б" => "b", "в" => "v", "г" => "g", "д" => "d", "е" => "e", "ё" => "yo", "ж" => "j", "з" => "z", "и" => "i", "й" => "i", "к" => "k", "л" => "l", "м" => "m", "н" => "n", "о" => "o", "п" => "p", "р" => "r", "с" => "s", "т" => "t", "у" => "y", "ф" => "f", "х" => "h", "ц" => "c", "ч" => "ch", "ш" => "sh", "щ" => "sh", "ы" => "i", "э" => "e", "ю" => "u", "я" => "ya",
            "А" => "A", "Б" => "B", "В" => "V", "Г" => "G", "Д" => "D", "Е" => "E", "Ё" => "Yo", "Ж" => "J", "З" => "Z", "И" => "I", "Й" => "I", "К" => "K", "Л" => "L", "М" => "M", "Н" => "N", "О" => "O", "П" => "P", "Р" => "R", "С" => "S", "Т" => "T", "У" => "Y", "Ф" => "F", "Х" => "H", "Ц" => "C", "Ч" => "Ch", "Ш" => "Sh", "Щ" => "Sh", "Ы" => "I", "Э" => "E", "Ю" => "U", "Я" => "Ya",
            "ь" => "", "Ь" => "", "ъ" => "", "Ъ" => ""
        );
        // Заменяем непригодные символы на чёрточки
        $title = preg_replace('/[^a-zа-яё\d_-]+/isu', '-', $title);
        // Убираем чёрточки из начала и конца строки
        $title = trim($title, '-');
        $title = strtr($title, $trans);
        if ($to_lower) {
            $title = mb_strtolower($title, $this->_encoding);
        }
        if (mb_strlen($title, $this->_encoding) > $maxLength) {
            $title = mb_substr($title, 0, $maxLength, $this->_encoding);
        }

        // Возвращаем пригодную строку, где русские символы переведены в транслит
        return $title;
    }
}