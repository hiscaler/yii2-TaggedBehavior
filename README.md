# yii2-TaggedBehavior
Yii2 Tagged Behavior

# 安装
使用 composer，在命令行下使用以下命令：

```php
composer require "yadjet/yii2-tagged-behavior:dev-master" 
```

# 使用
```php
public function behaviors() {
    return array_merge(parent::behaviors(), [
        [
            'class' => TaggedBehavior::className(),
            'attribute' => 'tags',
        ],
    ]);
}
```