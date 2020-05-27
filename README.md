# User Commands Bundle


## Install

```shell script
composer require dwo/user-commands-bundle:@dev
```

and add to **config\bundles.php**
```php
return [
    //..

    Dwo\UserCommandsBundle\DwoUserCommandsBundle::class => ['all' => true],

    //..
];
```

## Commands

```shell script
php console dwo:user:create
```

```shell script
php console dwo:user:update
```

```shell script
php console dwo:user:list
```