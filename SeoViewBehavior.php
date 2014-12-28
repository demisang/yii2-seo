<?php
/**
 * Поведение для работы с SEO параметрами во view
 */

namespace demi\seo;

use Yii;
use yii\base\Behavior;
use yii\web\View;
use yii\helpers\Html;

/**
 * Управление установкой SEO-параметров для страницы
 *
 * @package demi\seo
 */
class SeoViewBehavior extends Behavior
{
    private $_page_title = '';
    private $_meta_description = '';
    private $_meta_keywords = '';
    private $_noIndex = false;

    public function events()
    {
        return [
//            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
//            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
//            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
//            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
//            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    /**
     * Установка meta параметров страницы
     *
     * @param mixed $title 1) массив:
     *                     array("title"=>"Page Title", "desc"=>"Page Descriptions", "keys"=>"Page, Keywords")
     *                     2) SeoModelBehavior
     *                     3) Строка для title страницы
     * @param string $desc Meta description
     * @param mixed $keys  Meta keywords, строка либо массив ключевиков
     *
     * @return static
     */
    public function setSeoData($title, $desc = '', $keys = '')
    {
        $data = $title;
        if ($title instanceof SeoModelBehavior) {
            // Вытаскиваем данные из модельки, в которой есть SeoModelBehavior
            $meta = $title->getSeoData();
            $data = [
                'title' => $meta[SeoModelBehavior::TITLE_KEY],
                'desc' => $meta[SeoModelBehavior::DESC_KEY],
                'keys' => $meta[SeoModelBehavior::KEYS_KEY],
            ];
        } elseif (is_string($title)) {
            $data = array(
                'title' => $title,
                'desc' => $desc,
                'keys' => !is_array($keys) ? $keys : implode(', ', $keys),
            );
        }
        if (isset($data['title'])) {
            $this->_page_title = $this->normalizeStr($data['title']);
        }
        if (isset($data['desc'])) {
            $this->_meta_description = $this->normalizeStr($data['desc']);
        }
        if (isset($data['keys'])) {
            $this->_meta_keywords = $this->normalizeStr($data['keys']);
        }

        return $this;
    }

    public function renderMetaTags()
    {
        /* @var $view View */
        $view = $this->owner;
        $title = !empty($this->_page_title) ? $this->_page_title . ' - ' . Yii::$app->name : Yii::$app->name;
        echo '<title>' . Html::encode($this->normalizeStr($title)) . '</title>' . PHP_EOL;
        if (!empty($this->_meta_description)) {
            $view->registerMetaTag(['name' => 'description', 'content' => Html::encode($this->normalizeStr($this->_meta_description))]);
        }
        if (!empty($this->_meta_keywords)) {
            $view->registerMetaTag(['name' => 'keywords', 'content' => Html::encode($this->normalizeStr($this->_meta_keywords))]);
        }
        if (!empty($this->_noIndex)) {
            $view->registerMetaTag(['name' => 'robots', 'content' => $this->_noIndex]);
        }
    }

    /**
     * Нормализует строку, подготоваливает её для отображения
     *
     * @param string $str
     *
     * @return string
     */
    private function normalizeStr($str)
    {
        // Удаляем теги из текста
        $str = strip_tags($str);
        // Заменяем все пробелы, переносы строк и табы на один пробел
        $str = trim(preg_replace('/[\s]+/is', ' ', $str));

        return $str;
    }

    /**
     * Установить meta-тег noindex для текущей страницы
     *
     * @param boolean $follow Разрешить поисковикам следовать по ссылкам? Если FALSE,
     *                        то в мета-тег будет добавлено nofollow
     */
    public function noIndex($follow = true)
    {
        $content = 'noindex, ' . ($follow ? 'follow' : 'nofollow');

        $this->_noIndex = $content;
    }
}