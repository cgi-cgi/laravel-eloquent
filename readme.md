# Eloquent Joins

[![Latest Stable Version](https://poser.pugx.org/cgi/eloquent/v/stable)](https://packagist.org/packages/cgi/eloquent)
[![Total Downloads](https://poser.pugx.org/cgi/eloquent/downloads)](https://packagist.org/packages/cgi/eloquent)
[![Latest Unstable Version](https://poser.pugx.org/cgi/eloquent/v/unstable)](https://packagist.org/packages/cgi/eloquent)
[![License](https://poser.pugx.org/cgi/eloquent/license)](https://packagist.org/packages/cgi/eloquent)
[![Monthly Downloads](https://poser.pugx.org/cgi/eloquent/d/monthly)](https://packagist.org/packages/cgi/eloquent)
[![Daily Downloads](https://poser.pugx.org/cgi/eloquent/d/daily)](https://packagist.org/packages/cgi/eloquent)
[![composer.lock](https://poser.pugx.org/cgi/eloquent/composerlock)](https://packagist.org/packages/cgi/eloquent)

This package allows you to simply call `$model->join($relation)` to join a Laravel Eloquent relationship's table on the keys declared by your relationship. Columns will be selected automatically,  and the joined records hydrated as models in the resulting collection. Laravel's Eloquent does support joins normally, but internally calls the underlying query builder, thereby expecting the name of a table and keys to join it on as arguments.

## Installation

Eloquent Joins is installable [with Composer via Packagist](https://packagist.org/packages/cgi/eloquent).

## Usage

### Use trait

Simply use Llama\Database\Eloquent\ModelTrait in a model:

```php
namespace App;

use Llama\Database\Eloquent\ModelTrait;

class User
{
    use ModelTrait;

    protected $table = 'users';

    public function orders()
    {
        return $this->hasMany(\App\Order::class);
    }
}

$users = \App\User::join('orders')->get();
```

In the above example, `$users` will contain a collection of all the User models with a corresponding Order model (since we've performed an inner join). Each corresponding Order model can be found in the `$orders` property on the User model (normally this would contain a collection of models with a matching foreign key).

You can string multiple `join()` calls, as well as use the other types of join normally available on the underlying query object (`joinWhere()`, `leftJoin()`, etc.).

### Licence

Eloquent Joins is free and gratis software licensed under the [MIT licence]. This allows you to use Eloquent Joins for commercial purposes, but any derivative works (adaptations to the code) must also be released under the same licence.
