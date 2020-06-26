<?php
/**
 * @copyright Copyright (c) 2018 Ivan Orlov
 * @license   https://github.com/demisang/yii2-seo/blob/master/LICENSE
 * @link      https://github.com/demisang/yii2-seo#readme
 * @author    Ivan Orlov <gnasimed@gmail.com>
 */

namespace demi\seo;

use Yii;
use yii\base\Behavior;
use yii\web\View;
use yii\helpers\Html;

/**
 * Manage page seo-params
 *
 * @property View $owner
 */
class SeoViewBehavior extends Behavior
{
    /**
     * <title> tag content template
     *
     * @var string
     */
    public $titleTemplate = '{title} - {appName}';
    /**
     * <meta name="description"> tag content value template
     *
     * @var string
     */
    public $descriptionTemplate = '{description}';
    /**
     * <meta name="keywords"> tag content value template
     *
     * @var string
     */
    public $keywordsTemplate = '{keywords}';

    protected $_page_title = '';
    protected $_meta_description = '';
    protected $_meta_keywords = '';
    protected $_noIndex = false;

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
     * Set page meta
     *
     * @param string|SeoModelBehavior $title 1) array:
     *                                       ["title"=>"Page Title", "desc"=>"Page Descriptions", "keys"=>"Page, Keywords"]
     *                                       2) SeoModelBehavior
     *                                       3) Page title string
     * @param string $desc                   Meta description string
     * @param string|string[] $keys          Meta keywords: string or keywords array
     *
     * @return static
     */
    public function setSeoData($title, $desc = '', $keys = '')
    {
        $data = $title;
        if ($title instanceof SeoModelBehavior) {
            // Get data from model with SeoModelBehavior
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
            $this->_page_title = $this->owner->title = $this->normalizeStr($data['title']);
        }
        if (isset($data['desc'])) {
            $this->_meta_description = $this->normalizeStr($data['desc']);
        }
        if (isset($data['keys'])) {
            $this->_meta_keywords = $this->normalizeStr($data['keys']);
        }

        return $this;
    }

    /**
     * Print tags:
     * <title>
     *
     * Register meta tags:
     * <meta name="description">
     * <meta name="keywords">
     *
     * and optional:
     * <meta name="robots" content="noindex, [follow|nofollow]">
     */
    public function renderMetaTags()
    {
        $view = $this->owner;

        if ($this->_page_title) {
            // If title set - use it
            $title = $this->_page_title;
        } elseif ($view->title) {
            // Use default Yii title value
            $title = $view->title;
        }

        if (!empty($title)) {
            // Make title by template
            $title = strtr($this->titleTemplate, [
                '{title}' => $title,
                '{appName}' => Yii::$app->name,
            ]);
        } else {
            // Otherwise by default use App name for title
            $title = Yii::$app->name;
        }

        // Print <title> tag
        echo '<title>' . Html::encode($this->normalizeStr($title)) . '</title>' . PHP_EOL;

        // Register <meta name="description"> tag
        if (!empty($this->_meta_description)) {
            $description = str_replace('{description}', $this->_meta_description, $this->descriptionTemplate);
            $view->registerMetaTag(['name' => 'description', 'content' => Html::encode($this->normalizeStr($description))]);
        }

        // Register <meta name="keywords"> tag
        if (!empty($this->_meta_keywords)) {
            $keywords = str_replace('{keywords}', $this->_meta_keywords, $this->keywordsTemplate);
            $view->registerMetaTag(['name' => 'keywords', 'content' => Html::encode($this->normalizeStr($keywords))]);
        }

        // Register <meta name="robots"> tag
        if (!empty($this->_noIndex)) {
            $view->registerMetaTag(['name' => 'robots', 'content' => $this->_noIndex]);
        }
    }

    /**
     * Setup noindex <link> tag for current page
     *
     * @param boolean $follow Allow search engine links following?
     */
    public function noIndex($follow = true)
    {
        $content = 'noindex, ' . ($follow ? 'follow' : 'nofollow');

        $this->_noIndex = $content;
    }

    /**
     * Page title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->_page_title;
    }

    /**
     * Page description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->_meta_description;
    }

    /**
     * Page keywords
     *
     * @return string
     */
    public function getKeywords()
    {
        return $this->_meta_keywords;
    }

    /**
     * String normalizer
     *
     * @param string $str
     *
     * @return string
     */
    protected function normalizeStr($str)
    {
        // Remove html-tags
        $str = strip_tags($str);
        // Replace 2+ spaces to one
        $str = trim(preg_replace('/[\s]+/is', ' ', $str));

        return $str;
    }
}
