<?php

namespace Scout\Solr\Facades;

use Illuminate\Support\Facades\Facade;
use Scout\Solr\ClientInterface;


class Solr extends Facade
{
    protected static function getFacadeAccessor()  {
        return ClientInterface::class;
    }
}
