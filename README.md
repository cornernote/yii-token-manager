# Yii Token Manager

Manages tokens that can be validated, used and expired.


Yii config:

```
    'components' => array(
        'tokenManager' => array(
            'class' => 'vendor.cornernote.yii-token-manager.token-manager.components.ETokenManager',
            'connectionID' => 'db',
        ),
    ),
```
