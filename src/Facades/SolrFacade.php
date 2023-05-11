<?php

namespace Scout\Solr\Facades;

use Illuminate\Support\Facades\Facade;
use Scout\Solr\Client;

class Solr extends Facade
{
    protected static function getFacadeAccessor()  {
        return Client::class;
    }
}
