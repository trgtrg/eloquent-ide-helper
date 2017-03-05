## Eloquent IDE Helper Generator

[![Software License][ico-license]](LICENSE.md)

### Complete phpDocs, directly from the source

This is a fork of barryvdh/laravel-ide-helper for generating Elqouent model phpDocs outside of Laravel.  It extends from a Symfony Command so it can be used with the Symfony Console component.

This package generates a file that your IDE understands, so it can provide accurate autocompletion. Generation is done based on the files in your project, so they are always up-to-date.
If you don't want to generate it, you can add a pre-generated file to the root folder of your project (but this isn't as up-to-date as self generated files).

Note: You do need CodeIntel for Sublime Text: https://github.com/SublimeCodeIntel/SublimeCodeIntel


### Configuration
The included commands take in an array of configuration options on construction.  These options define the default behavior when the commands are run without options.

```php
$application = new Symfony\Component\Console\Application();

$application->add(new CarterZenk\EloquentIdeHelper\ModelsCommand([
    'modelDirectories' => [
        __DIR__.'/src/Models'
    ],
    'outputFile' => __DIR__.'/_ide_helper_models.php'
]));

$application->add(new CarterZenk\EloquentIdeHelper\Command\GeneratorCommand([
    'outputFile' => __DIR__.'/_ide_helper_facades.php'
    'format' => 'php'
]));

$application->run();
```


### Automatic phpDocs for models

> You need to require `doctrine/dbal: ~2.3` in your own composer.json to get database columns.


```bash
composer require doctrine/dbal
```

If you don't want to write your properties yourself, you can use the command `php {your-script} ide-helper:models` to generate
phpDocs, based on table columns, relations and getters/setters. You can write the comments directly to your Model file, using the `--write (-W)` option. By default, you are asked to overwrite or write to a separate file (`_ide_helper_models.php`). You can force No with `--nowrite (-N)`.
Please make sure to backup your models, before writing the info.
It should keep the existing comments and only append new properties/methods. The existing phpdoc is replaced, or added if not found.
With the `--reset (-R)` option, the existing phpdocs are ignored, and only the newly found columns/relations are saved as phpdocs.

```bash
php {your-script} ide-helper:models Post
```

```php
/**
 * An Eloquent Model: 'Post'
 *
 * @property integer $id
 * @property integer $author_id
 * @property string $title
 * @property string $text
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \User $author
 * @property-read \Illuminate\Database\Eloquent\Collection|\Comment[] $comments
 */
```

By default, models in the `modelDirectories` setting are scanned. The optional argument tells what models to use.

```bash
php {your-script} ide-helper:models Post User
```

You can also scan a different directory, using the `--dir` option (relative from the base path):

```bash
php {your-script} ide-helper:models --dir="path/to/models" --dir="app/src/Model"
```

Models can be ignored using the `--ignore (-I)` option

```bash
php {your-script} ide-helper:models --ignore="Post,User"
```

Note: With namespaces, wrap your model name in " signs: `php {your-script} ide-helper:models "API\User"`, or escape the slashes (`Api\\User`)


### Automatic phpDocs for the Eloquent facade

This command will generate autocomplete documentation for the magic methods in the Eloquent facade.

```bash
php {your-script} ide-helper:generator
```

### License

The Eloquent IDE Helper Generator is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)

[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square