<?php

namespace demi\seo;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\validators\UniqueValidator;
use yii\validators\Validator;
use yii\widgets\ActiveForm;

/**
 * Behavior to work with SEO meta options
 *
 * @package demi\seo
 *
 * @property ActiveRecord $owner
 */
class SeoModelBehavior extends Behavior
{
    /** @var string The name of the field responsible for SEO:url */
    private $_urlField;
    /** @var string|callable The name of the field which will form the SEO:url, or function,
     * that returns the text to be processed to generate SEO:url */
    private $_urlProduceField = 'title';
    /** @var string|callable PHP-expression that generates field SEO:title
     * example: function($model, $lang) { return $model->{"title_".$lang} } */
    private $_titleProduceFunc;
    /** @var string|callable PHP-expression that generates field SEO:desciption */
    private $_descriptionProduceFunc;
    /** @var string|callable PHP-expression that generates field SEO:keywords */
    private $_keysProduceFunc;
    /**
     * @var string The name of the resulting field, which will be stored in a serialized form of all SEO-parameters
     */
    private $_metaField;
    /** @var boolean|callable Whether to allow the user to change the SEO-data */
    private $_clientChange = true;
    /** @var integer The maximum length of the field SEO:url */
    private $_maxUrlLength = 70;
    /** @var integer The maximum length of the field Title */
    private $_maxTitleLength = 70;
    /** @var integer The maximum length of the field Description */
    private $_maxDescLength = 130;
    /** @var integer The maximum length of the field Keywords */
    private $_maxKeysLength = 150;
    /** @var array Forbidden for use SEO:url names */
    private $_stopNames = ['create', 'update', 'delete', 'view', 'index'];
    /** @var string SEO route for creating link, example "post/view" */
    private $_viewRoute = '';
    /** @var string Parameter name, which will be given SEO:url in the creation of link
     * Here is an example of a place where this option is used: Url::to(["route", linkTitleParamName=>seo_url]) */
    private $_linkTitleParamName = 'title';
    /** @var array|callable additional parameters SEO:url to create a link */
    private $_additionalLinkParams = [];
    /** @var array List of languages that should be available to SEO-options */
    private $_languages = [];
    /** @var string The name of a controller class, which operates the current model.
     * Must be specified for a list of actions of the controller to seo_url
     * did not coincide with the existing actions in the controller */
    private $_controllerClassName = '';
    /** @var boolean Is it necessary to use only lowercase when generating seo_url */
    private $_toLowerSeoUrl = true;
    /** @var Query Additional criteria when checking the uniqueness of seo_url */
    private $_uniqueUrlFilter;
    /** @var string Regular expression to verify that the request goes to bypass seo_url.
     * search string - Yii::app()->request->pathInfo
     * If the request is passed to bypass, then it will redirect to the correct of viewUrl */
    private $_checkSeoUrlRegexp = '';
    /** @var string encoding site */
    private $_encoding = 'UTF-8';
    /** @var array Array configuration that overrides the above settings */
    public $seoConfig = [];
    const TITLE_KEY = 'title';
    const DESC_KEY = 'desc';
    const KEYS_KEY = 'keys';
    /** @var array Saved actions of controllers for SEO:url stop list */
    private static $_controllersActions = [];

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    public function attach($owner)
    {
        parent::attach($owner);

        // Apply the configuration options
        foreach ($this->seoConfig as $key => $value) {
            $var = '_' . $key;
            $this->$var = $value;
        }

        $this->_languages = (array)$this->_languages;
        // If there was not passed any language - we use only one system language
        if (!count($this->_languages)) {
            $this->_languages = [Yii::$app->language];
        }
        // if the current user can see and edit SEO-data model
        if (is_callable($this->_clientChange)) {
            $this->_clientChange = call_user_func($this->_clientChange, $owner);
        }

        // If the route to create a seo url link to view model is not specified - generate it
        if (empty($this->_viewRoute)) {
            $this->_viewRoute = strtolower(basename(get_class($owner))) . '/view';
        }

        // Determine the controller and add it actions to the seo url stop list
        if (!empty($this->_urlField) && !empty($this->_controllerClassName)) {
            if (isset(static::$_controllersActions[$this->_controllerClassName])) {
                // Obtain the previously defined controller actions
                $buffer = static::$_controllersActions[$this->_controllerClassName];
            } else {
                // Get all actions of controller
                $reflection = new \ReflectionClass($this->_controllerClassName);
                $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                $controller = $reflection->newInstance(Yii::$app->getUniqueId(), null);
                // Add all reusable controller actions
                $buffer = array_keys($controller->actions());
                // Loop through all the main controller actions
                foreach ($methods as $method) {
                    /* @var $method \ReflectionMethod */
                    $name = $method->getName();
                    if ($name !== 'actions' && substr($name, 0, 6) == 'action') {
                        $action = substr($name, 6, strlen($name));
                        $action[0] = strtolower($action[0]);
                        $buffer[] = $action;
                    }
                }

                // Save controller actions for later use
                static::$_controllersActions[$this->_controllerClassName] = $buffer;
            }

            // Merge controller actions with actions from config behavior
            $this->_stopNames = array_unique(array_merge($this->_stopNames, $buffer));
        }
    }

    public function beforeValidate()
    {
        $model = $this->owner;

        if (!empty($this->_metaField)) {
            // Loop through all the languages available, it is often only one
            foreach ($this->_languages as $lang) {
                // Loop through the fields and cut the long strings to set allowed length
                foreach ($this->getMetaFields() as $meta_param_key => $meta_param_value_generator) {
                    $this->applyMaxLength($meta_param_key, $lang);
                }
            }
        }

        if (empty($this->_urlField)) {
            // If do not need to work with SEO:url, then skip further work
            return;
        }

        // Add UNIQUE validator for SEO:url field
        $validator = Validator::createValidator(UniqueValidator::className(), $model, $this->_urlField, [
            'filter' => $this->_uniqueUrlFilter,
        ]);

        // If SEO: url is not filled by the user, then generate its value
        $urlFieldVal = trim((string)$model->{$this->_urlField});
        if ($urlFieldVal === '') {
            $urlFieldVal = $this->getProduceFieldValue($this->_urlProduceField);
        }
        // Transliterated string and remove from it the extra characters
        $seoUrl = $this->getSeoName($urlFieldVal, $this->_maxUrlLength, $this->_toLowerSeoUrl);

        // If there is a match with banned names, then add to the url underbar to the end
        while (in_array($seoUrl, $this->_stopNames)) {
            $seoUrl .= '_';
        }

        $model->setAttribute($this->_urlField, $seoUrl);
        // Start the first unique validation
        $validator->validateAttribute($model, $this->_urlField);

        // Run the validation of up to 50 times, until there is a unique SEO:url name
        $i = 0;
        while ($model->hasErrors($this->_urlField)) {
            // Remove the error message received in the last validation
            $model->clearErrors($this->_urlField);

            // If failed 50 times, then something went wrong...
            if (++$i > 50) {
                // We establish SEO: url to a random hash
                $model->setAttribute($this->_urlField, md5(uniqid()));
                // Finish "infinite" loop
                break;
            }

            // Add "_" at the end of SEO:url
            $newSeoUrl = $model->{$this->_urlField . '_'};
            $model->setAttribute($this->_urlField, $newSeoUrl);
            // Run the validator again, because in the previous line, we changed the value of adding a suffix
            $validator->validateAttribute($model, $this->_urlField);
        }
    }

    /**
     * Verifies the maximum length of meta-strings, and if it exceeds the limit - cuts to the maximum value
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
        $this->beforeValidate();

        if (empty($this->_metaField)) {
            // Unless specified meta-field, then we will not save
            return;
        }

        // Check all the SEO field and populate them with data, if specified by the user - leave as is, if there is no - generate
        $this->fillMeta();

        $meta = $model->{$this->_metaField};

        // Save all data in a serialized form
        $model->setAttribute($this->_metaField, serialize($meta));
    }

    /**
     * Checks completion of all SEO:meta fields. In their absence, they will be generated.
     */
    private function fillMeta()
    {
        // Loop through all the languages available, it is often only one
        foreach ($this->_languages as $lang) {
            // Loop through the meta-fields and fill them in the absence of complete data
            foreach ($this->getMetaFields() as $meta_params_key => $meta_param_value_generator) {
                $meta_params_val = $this->getMetaFieldVal($meta_params_key, $lang);
                if (empty($meta_params_val) && $meta_param_value_generator !== null) {
                    // Get value from the generator
                    $meta_params_val = $this->getProduceFieldValue($meta_param_value_generator, $lang);
                    $this->setMetaFieldVal($meta_params_key, $lang, $this->normalizeStr($meta_params_val));
                }
                // We verify that the length of the string in the normal
                $this->applyMaxLength($meta_params_key, $lang);
            }
        }
    }

    public function afterFind()
    {
        $model = $this->owner;

        if (!empty($this->_metaField)) {
            // Unpack meta-params
            $meta = @unserialize($model->{$this->_metaField});
            if (!is_array($meta)) {
                $meta = [];
            }

            $model->setAttribute($this->_metaField, $meta);
        }
    }

    /**
     * Returns an array of meta-fields. As the value goes callback function
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
     * Returns the value of the $key SEO:meta for the specified $lang language
     *
     * @param string $key  key TITLE_KEY, DESC_KEY or KEYS_KEY
     * @param string $lang language
     *
     * @return string|null
     */
    private function getMetaFieldVal($key, $lang)
    {
        $param = $key . '_' . $lang;
        $meta = $this->owner->{$this->_metaField};

        return is_array($meta) && isset($meta[$param]) ? $meta[$param] : null;
    }

    /**
     * Sets the value of $key in SEO:meta on the specified $lang language
     *
     * @param string $key   key TITLE_KEY, DESC_KEY or KEYS_KEY
     * @param string $lang  language
     * @param string $value field value
     */
    private function setMetaFieldVal($key, $lang, $value)
    {
        $model = $this->owner;
        $param = $key . '_' . $lang;
        $meta = $model->{$this->_metaField};
        if (!is_array($meta)) {
            $meta = [];
        }

        $meta[$param] = (string)$value;

        $model->setAttribute($this->_metaField, $meta);
    }

    /**
     * Returns the metadata for this model
     *
     * @param string|null $lang language, which requires meta-data
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
            // Check that all meta-fields were filled with the values
            $this->fillMeta();
        }

        // If meta stored in a model, then refund the value of the model fields, otherwise will generate data on the fly
        $getValMethodName = !empty($this->_metaField) ? 'getMetaFieldVal' : 'getProduceFieldValue';

        foreach ($this->getMetaFields() as $meta_params_key => $meta_param_value_generator) {
            // Choosing what parameters are passed to the function get the value: the name of the field or function-generator
            $getValMethodParam = !empty($this->_metaField) ? $meta_params_key : $meta_param_value_generator;
            // Directly receiving the value of any meta-field of the model or generate it
            $buffer[$meta_params_key] = $this->$getValMethodName($getValMethodParam, $lang);
        }


        return $buffer;
    }

    /**
     * Return instance of current behavior
     *
     * @return SeoModelBehavior $this
     */
    public function getSeoBehavior()
    {
        return $this;
    }

    /**
     * Normalize string, prepares it for recording in the database
     *
     * @param string $str
     *
     * @return string
     */
    private function normalizeStr($str)
    {
        // Replace all spaces, line breaks and tabs with a single space char
        $str = preg_replace('/[\s]+/iu', ' ', $str);
        $str = trim(strip_tags($str));

        return $str;
    }

    /**
     * Returns the URL to the page viewing for current model-owner
     *
     * @param string $title  SEO:title for the url, use only if the reference is not generated from the model, and externally
     * @param string $anchor #Anchor, which is added to the url ($title can be set to NULL)
     * @param boolean $abs   Need a absolute link ($title and $anchor can be set to NULL)
     *
     * @return string
     */
    public function getViewUrl($title = null, $anchor = null, $abs = false)
    {
        // If additional link parameters should be generated - do generate
        if (is_callable($this->_additionalLinkParams)) {
            $this->_additionalLinkParams = call_user_func($this->_additionalLinkParams, $this->owner);
        }

        // If the model does not have SEO: url, then the value of this field will be used by the Model ID
        if (empty($this->_urlField) && empty($title)) {
            $title = $this->owner->getPrimaryKey();
        }

        // Add the parameter that is responsible for displaying SEO:url
        $params = [$this->_linkTitleParamName => !empty($title) ? $title : $this->owner->{$this->_urlField}];

        // Adding anchor
        if (!empty($anchor)) {
            $params['#'] = $anchor;
        }

        return Url::to(array_merge([$this->_viewRoute], array_merge($params, $this->_additionalLinkParams)), $abs);
    }

    /**
     * Returns the absolute URL to the page view model-owner
     *
     * @param string $title  SEO:title for the url, use only if the reference is not generated from the model, and externally
     * @param string $anchor Якорь, который добвится к url, а $title можно указать как NULL#Anchor, which is added to the url ($title can be set to NULL)
     *
     * @return string
     */
    public function getAbsoluteViewUrl($title = null, $anchor = null)
    {
        return $this->getViewUrl($title, $anchor, true);
    }

    /**
     * Render the SEO field for a form
     *
     * @param ActiveForm $form
     */
    public function renderFormSeoFields($form = null)
    {
        // If the parameters can not be edited manually - it means nothing to display them in the form of
        if (!$this->_clientChange) {
            return;
        }

        $model = $this->owner;

        echo '<hr />';

        if ($form instanceof ActiveForm) {
            echo $form->field($model, $this->_urlField)->textInput();
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
                $label = 'SEO: ' . $meta_field_key;
                if (count($this->_languages) > 1) {
                    $label .= ' ' . strtoupper($lang);
                }
                if ($form instanceof ActiveForm) {
                    $input = $meta_field_key == self::DESC_KEY ? 'textarea' : 'textInput';
                    echo $form->field($model, $attr)->label($label)->$input();
                } else {
                    $input = $meta_field_key == self::DESC_KEY ? 'activeTextarea' : 'activeTextInput';
                    echo '<div class="seo_row">';
                    echo Html::activeLabel($model, $attr, ['label' => $label]);
                    echo Html::$input($model, $attr);
                    echo Html::error($model, $attr);
                    echo "</div>\n";
                }
            }
        }
    }

    /**
     * Returns the generated value for the meta-fields
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
            return (string)$model->{$produceFunc};
        }
    }

    /**
     * Verifies that only correct seo_url is used for current url
     * @return boolean true if all is well, otherwise occurs 301 redirect to the correct URL
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
     * Returns usable SEO name
     *
     * @param string $title
     * @param int $maxLength
     * @param bool $to_lower
     *
     * @return string
     */
    private function getSeoName($title, $maxLength = 255, $to_lower = true)
    {
        $trans = [
            "а" => "a", "б" => "b", "в" => "v", "г" => "g", "д" => "d", "е" => "e", "ё" => "yo", "ж" => "j", "з" => "z", "и" => "i", "й" => "i", "к" => "k", "л" => "l", "м" => "m", "н" => "n", "о" => "o", "п" => "p", "р" => "r", "с" => "s", "т" => "t", "у" => "y", "ф" => "f", "х" => "h", "ц" => "c", "ч" => "ch", "ш" => "sh", "щ" => "sh", "ы" => "i", "э" => "e", "ю" => "u", "я" => "ya",
            "А" => "A", "Б" => "B", "В" => "V", "Г" => "G", "Д" => "D", "Е" => "E", "Ё" => "Yo", "Ж" => "J", "З" => "Z", "И" => "I", "Й" => "I", "К" => "K", "Л" => "L", "М" => "M", "Н" => "N", "О" => "O", "П" => "P", "Р" => "R", "С" => "S", "Т" => "T", "У" => "Y", "Ф" => "F", "Х" => "H", "Ц" => "C", "Ч" => "Ch", "Ш" => "Sh", "Щ" => "Sh", "Ы" => "I", "Э" => "E", "Ю" => "U", "Я" => "Ya",
            "ь" => "", "Ь" => "", "ъ" => "", "Ъ" => ""
        ];
        // Replace the unusable characters on the dashes
        $title = preg_replace('/[^a-zа-яё\d_-]+/isu', '-', $title);
        // Remove dashes from the beginning and end of the line
        $title = trim($title, '-');
        $title = strtr($title, $trans);
        if ($to_lower) {
            $title = mb_strtolower($title, $this->_encoding);
        }
        if (mb_strlen($title, $this->_encoding) > $maxLength) {
            $title = mb_substr($title, 0, $maxLength, $this->_encoding);
        }

        // Return usable string
        return $title;
    }
}