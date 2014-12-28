yii2-seo
========
Library for working with SEO parameters of models

Installation
------------
```code
{
	"require":
	{
  		"demi/seo": "dev-master"
	}
}
```
Configuration
-------------

```php
return [
    'components' => [
        'view' => [
            'as seo' => [
                'class' => 'demi\seo\SeoViewBehavior',
            ]
        ],
    ],
];
```

In model file add seo model behavior:
```php
public function behaviors()
{
    $it = $this;

    return [
        'seo' => [
            'class' => \demi\seo\SeoModelBehavior::className(),
            'seoConfig' => [
                'urlField' => 'seo_url',
                'urlProduceField' => 'title',
                'titleProduceFunc' => 'title',
                'descriptionProduceFunc' => 'short_desc',
                'keysProduceFunc' => function ($model) {
                        return $model->title . ', tag1, tag2';
                    },
                'metaField' => 'seo_meta',
                'clientChange' => Yii::$app->has('user') && Yii::$app->user->can(User::ROLE_ADMIN),
                'viewRoute' => '/post/view',
                'additionalLinkParams' => function ($model) {
                        return ['category' => $model->category->seo_url];
                    },
                'languages' => 'ru',
                'controllerClassName' => 'PostController',
                'uniqueUrlFilter' => function ($query) use ($it) {
                        /* @var $query Query */
                        $query->andWhere(['category_id' => $it->category_id]);
                    },
            ],
        ],
    ];
}
```
Usage
-----
In view-file "view.php" for model:
```php
$this->setSeoData($model->getSeoBehavior());
```


