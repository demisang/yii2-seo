yii2-seo
========
Library for working with SEO parameters of models

How it works:
-------------
You add 2 fields to you model: 
- `seo_url` VARCHAR: it is unique model name, example: `http://example.com/post/<seo_url>`.  
- `seo_meta` TEXT: it is serialized array: `['title' => 'Post title', 'desc' => 'Post description', 'keys' => 'Post title, other keywords...']`.    

This behavior help you with:
- Generate `seo_url` from model title considering your unique-conditions. 
- Internationalization for `seo_meta`.
- Get model view url by simple call `$model->viewUrl`. (return relative url `/post/my-first` and even `/cat1/cat2/awesome-post` is possible).
- Output model seo fields(internationalized) to html: `<title>`, `<meta name="description">` and `<meta name="keywords">`
- Form fields for `seo_url` and all `seo_meta` and internationalizations fields if needed.
- You can(must) configure `seo_meta` fields generator, for example: keywords is: `<postTitle>, key1, key2, <postTags>, ...`.

Examples of generated `seo_url` for some cases:
- `http://example.com/first-category/awesome-post` - post1, cat1, `seo_url` is `awesome-post`
- `http://example.com/other-category/awesome-post` - post2, cat2, `seo_url` is `awesome-post` also possible
- `http://example.com/first-category/awesome-post_` - post3, cat1, `seo_url` is `awesome-post_` (add underscore at end if name in post category already used)

Installation
------------
Run
```code
composer require "demi/seo" "~1.0"
```

Configuration
-------------
Each view should have access to `demi\seo\SeoViewBehavior` methods.
So configure `/frontend/config/main.php`:
```php
return [
    'components' => [
        'view' => [
            'as seo' => [
                'class' => 'demi\seo\SeoViewBehavior',
                // options by default:
                'titleTemplate' => '{title} - {appName}',
                'descriptionTemplate' => '{description}',
                'keywordsTemplate' => '{keywords}',
            ]
        ],
    ],
];
```

In model file add seo model behavior:
```php
<?php

public function behaviors()
{
    return [
        'seo' => [
            'class' => 'demi\seo\SeoModelBehavior',
            'seoConfig' => [
                'urlField' => 'seo_url',
                'metaField' => 'seo_meta',
                // attribute name or anonymous function
                'urlProduceField' => 'title',
                // attribute name or anonymous function
                'titleProduceFunc' => 'title',
                // attribute name or anonymous function
                'descriptionProduceFunc' => 'short_desc',
                // attribute name or anonymous function
                'keysProduceFunc' => function (self $model) {
                    return $model->title . ', tag1, tag2';
                },
                // when user can manage model seo-fields (anonymous function possible) 
                'clientChange' => Yii::$app->has('user') && Yii::$app->user->can(User::ROLE_ADMIN),
                // param for Url::to(<viewRoute>)
                'viewRoute' => '/post/view',
                // param for Url::to(<viewRoute>, ['title' => $model->seo_url])
                'linkTitleParamName' => 'title',
                // only anon-function returns array of additional link params with values
                'additionalLinkParams' => function (self $model) {
                    // Url::to(<viewRoute>, ['title' => $model->seo_url, 'category' => $model->category->seo_url])
                    return ['category' => $model->category->seo_url];
                },
                // if you model have some unique condition
                'uniqueUrlFilter' => function (\yii\db\Query $query) {
                    $query->andWhere(['category_id' => $this->category_id]);
                },
                // if array - seo_meta will have possible to internationalization 
                'languages' => 'en',
                // Optional. All controller actions will added to stop-list for seo_url value.
                // For example: if you create model with seo_url = 'delete' you can't open model by url '/post/delete',
                // if this option enabled, then seo_url will be 'delete_' and url: '/post/delete_' 
                'controllerClassName' => '\frontend\controllers\PostController',
            ],
        ],
    ];
}
```

PHPdoc for model:
```php
/**
 * @property-read  array $seoData
 * @method array getSeoData($lang = null) Metadata for this model
 * @method \demi\seo\SeoModelBehavior getSeoBehavior()
 * @property-read array $viewUrl
 * @method array getViewUrl() URL to material view page
 * @property-read  array $absoluteViewUrl
 * @method array getAbsoluteViewUrl() Absolute URL to material view page
 */
```
Change `/frontend/views/layouts/main.php`:
```php
<?php
/* @var $this \yii\web\View|\demi\seo\SeoViewBehavior */
?>
<head>
    <!-- Replace default <title> tag -->    
    <title><?= Html::encode($this->title) ?></title>
    <!-- by this line: -->    
    <?php $this->renderMetaTags(); ?>
    ...
</head>
```

Usage
-----
In "view.php" file for model:
```php
// set SEO:meta data for current page
$this->setSeoData($model->getSeoBehavior());

// Helper: set <link> tag for "no index" (and optional "no follow") for current page
$this->noIndex($and_no_follow_bool);
```
or in controller:
```php
Yii::$app->view->setSeoData($model->getSeoBehavior());
Yii::$app->view->noIndex($and_no_follow_bool);
```

Simple url rules example in '/frontend/config/main.php':
```php
'urlManager' => [
    'enablePrettyUrl' => true,
    'showScriptName' => false,
    'rules' => [
        'post/<action:(index|create|update|delete)>' => 'post/<action>',
        'post/<title:[-\w]+>' => 'post/view',
    ],
],
```
and change `/frontend/controllers/PostController.php`:
```php
public function actionView($title)
{
    $model = Post::find()->where(['seo_url' => $title])->one();
    if (!$model) {
        throw new NotFoundHttpException('Post not found');
    }

    return $this->render('view', [
        'model' => $model,
    ]);
}

// And in actionCreate() and actionUpdate() change
return $this->redirect(['view', 'id' => $model->id]);
// to
return $this->redirect($model->viewUrl);
```

Get link to model view page. Based on behavior config values: `viewRoute` and `additionalLinkParams`
```php
// return url to material view page: '/post/super-post'
$url = $model->getViewUrl();
$url = $model->viewUrl;

// return absolute url to material view page: 'http://example.com/post/super-post'
$abs_url = $model->getAbsoluteViewUrl();
$abs_url = $model->absoluteViewUrl;

// Behind scene (for understanding):
return Url::to([$viewRoute, ['title' => $model->$titleField] + $additionalLinkParams]], $isAbsolute);
```

Render SEO:url and SEO:meta fields in the "/frontend/views/post/_form.php" file:
```php
<?php
/* @var $model common\models\Post|demi\seo\SeoModelBehavior */
?>
...
<?php $model->renderFormSeoFields($ActiveForm_or_void); ?>
```
