# Laravel Scout Apache Solr driver
[![GitHub issues](https://img.shields.io/github/issues/Klaasie/laravel-scout-solr-engine)](https://github.com/Klaasie/laravel-scout-solr-engine/issues)
[![Latest Stable Version](http://poser.pugx.org/klaasie/scout-solr-engine/v)](https://packagist.org/packages/klaasie/scout-solr-engine)
[![License](http://poser.pugx.org/klaasie/scout-solr-engine/license)](https://packagist.org/packages/klaasie/scout-solr-engine) 
[![PHP Version Require](http://poser.pugx.org/klaasie/scout-solr-engine/require/php)](https://packagist.org/packages/klaasie/scout-solr-engine)

This package provides a basic implementation of the Apache Solr search engine within Laravel Scout.

## Installation

`composer require shopapps/scout-solr-engine`

## config

This package provides a config file that can be modified using .env variables.  
You can initialize your own config file with: 

`php artisan vendor:publish --provider="Scout\Solr\ScoutSolrServiceProvider"`

### scout:index

By default, Solr doesn't allow indexes (cores) to be created without providing the proper folders and files on the file system first.
However, if a default config set is set up in the Solr instance this becomes possible through the API.  
The `scout:index` command will only work if the Solr instance is properly configured and the config files has the corresponding name for the config set folder.
For more information, see [https://solr.apache.org/guide/8_9/config-sets.html#config-sets](https://solr.apache.org/guide/8_9/config-sets.html#config-sets)

To get the `_default` configset onto your server try the following (adjusting the command to match your solr version/location:

```bash
sudo cp -r /opt/solr-9.2.1/server/solr/configsets /var/solr/data
sudo chown -R solr:solr /var/solr/data/configsets
```

then checking that folder you should see something like:

```bash
sudo ls -lah /var/solr/data/configsets/

total 16K
drwxr-xr-x  4 solr solr 4.0K Jul 13 07:03 .
drwxr-x--- 13 solr solr 4.0K Jul 13 07:03 ..
drwxr-xr-x  3 solr solr 4.0K Jul 13 07:03 _default
drwxr-xr-x  3 solr solr 4.0K Jul 13 07:03 sample_techproducts_configs

```
most likely you can safely delete the `sample_techproducts_configs` folder from there unless you are using it :-)

### Cores (indexes)

Within the config file a core (index) is not provided. The engine will determine which core to connect to using the `searchableAs()` method on the model.

Alternatively, if a specific model is on a different Solr instance, another configuration can be provided for this model.
It's important for the configuration key to match the `searchableAs()` of the model.

When in Cloud mode i could not get this to work fully, so instead you could try:

#### Step 1: Create a collection in solr via admin panel.
```php
http://127.0.0.1:8983/solr/#/~collections
```
choose add collection and configure it across your shards and replicas ( for demo mode use shards: 2 and replicas: 2)

#### Step 2: Setup your schema in the Model
make sure your model is configured to use the correct Searchable trait
```php

<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use Scout\Solr\Traits\Searchable; // <--- THIS IS IMPORTANT


class User extends Model
{
    use HasFactory;
    use Searchable;
```

configure the searchable_fields property on your model, to use the schema you want to create in Solr.

```php

protected $searchable_fields = [
        'id'            => ['type' => 'string', 'indexed' => true, 'stored' => true],
        'role_id'       => ['type' => 'plong', 'indexed' => true, 'stored' => true],
        'name'          => ['type' => 'string', 'indexed' => true, 'stored' => true],
        'description'   => ['type' => 'text_general', 'indexed' => true, 'stored' => true],
        'is_active'     => ['type' => 'boolean', 'indexed' => true, 'stored' => true],
        'created_at'    => ['type' => 'pdate', 'indexed' => true, 'stored' => true],
        'updated_at'    => ['type' => 'pdate', 'indexed' => true, 'stored' => true],
    ];

```
#### Step 3: Build the schema
```php
$model = new \App\Models\User();
$model->buildSolrSchema();
```
this will log any issues to the laravel log file

## [Solarium](https://github.com/solariumphp/solarium)

This package uses [solarium/solarium](https://github.com/solariumphp/solarium) to handle requests to the solr instance.
This app is meant to be a simple implementation of the laravel/scout engine. For complex queries to the solr instance I would recommend initializing your own Solarium client and use that package.
Visit [https://solarium.readthedocs.io/en/stable/](https://solarium.readthedocs.io/en/stable/) to view the documentation of the solarium package.

For convenience, any unknown methods used on the engine will be forwarded to the solarium client.

```php
$model = new \App\Models\SearchableModel();

/** @var \Scout\Solr\Engines\SolrEngine $engine */
$engine = app(\Laravel\Scout\EngineManager::class)->engine();
$select = $engine->setCore($model)->createSelect();
$select->setQuery('*:*');
$result = $engine->select($select, $engine->getEndpointFromConfig($model->searchableAs())); // getEndpointFromConfig() is only necessary when your model does not use the default solr instance.
```

## Usage
not the best example but you get the idea
```php
$res = Product::search()
  ->where('owner', 1021)
  ->paginate();
```
or...

```php
$res = Product::search('description: "red" AND name: "car"')
  ->where('owner', 1021)
  ->paginate();
```
## Events
The Solr Engine dispatches several events allowing you to hook into specific points in the engine.

| Event | Usage |
|---------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
|Scout\Solr\Events\BeforeSelect|Contains the Solr `Solarium\QueryType\Select\Query\Query` object and Scout `Builder` object. This event allows you to create complex queries using the Solarium package.|
|Scout\Solr\Events\BeforeSelect|Contains the Solr `Solarium\QueryType\Select\Result\Result` object and `Model` object. This event allows you to create complex queries using the Solarium package.|


# Credits

This is a complete hash-up of lots of other great developers work.  I needed to get something working for a project but most packages were out of date for laravel 10, scout 10 and solr 9
If you spot your code in here, please accept my appology and let me know and i'll add you to the list:

* https://github.com/Klaasie/laravel-scout-solr-engine
* https://github.com/pxslip/laravel-scout-solr
* https://github.com/grey-dev-0/laravel-scout-solr
* https://gitlab.bertbalcaen.info/rekall/laravel-scout-solr
