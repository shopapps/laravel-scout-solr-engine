<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Solr Admin ConfigSet
    |--------------------------------------------------------------------------
    |
    | Set a default ConfigSet used for the createIndex command.
    | Without a ConfigSet Solr won't be able to create indexes through the command.
    |
    |
    */
    'create' => [
        'config_set' => env('SOLR_CONFIG_SET', '_default'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Solr Admin unload
    |--------------------------------------------------------------------------
    |
    | Settings used for the deleteIndex command.
    |
    |
    */
    'unload' => [
        'delete_index' => env('SOLR_UNLOAD_DELETE_INDEX', false),
        'delete_data_dir' => env('SOLR_UNLOAD_DELETE_DATA_DIR', false),
        'delete_instance_dir' => env('SOLR_UNLOAD_DELETE_INSTANCE_DIR', false),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Solr Select
    |--------------------------------------------------------------------------
    |
    | Solr does not allow for unlimited results. By default, Solr limits queries to 10 results.
    | When a limit is not provided through the builder instance this config is used instead.
    |
    |
    */
    'select' => [
        'limit' => env('SOLR_SELECT_DEFAULT_LIMIT', 999),
        /*
         * use_raw_data will return the raw data from Solr instead of the default Scout/Model collection.
         * in theory this should be faster as you are not doing an additional DB lookup for the model.
         * But you must make sure you are storing all the data you need in Solr.
         */
        'use_raw_data' => env('SOLR_SELECT_USE_RAW_DATA', false),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Solr Endpoint
    |--------------------------------------------------------------------------
    |
    | Set the default Solr instance for the client to work with.
    | A core is not necessary here, the client will set the right core based on the model searchableAs() value.
    |
    | If a model is stored on a different instance of Solr additional configs can be provided here.
    | It is important that the key of the configuration is equal to the models searchableAs()
    |
    |
    */
    'endpoints' => [
        'default' => [
            'host' => env('SOLR_HOST', 'localhost'),
            'port' => env('SOLR_PORT', 8983),
            'path' => env('SOLR_PATH', '/'),
            // Core is set through searchableAs()
        ],
        // Example of a core defined through config
        //        'books' => [
        //            'host' => env('SOLR_HOST', 'solr2'),
        //            'port' => env('SOLR_PORT', 8983),
        //            'path' => env('SOLR_PATH', '/'),
        //            'core' => env('SOLR_CORE', 'books'),
        //        ],
    ],
    
    'meta_key' => env('SOLR_META_KEY', 'meta'),
    /*
     * this is used when defining the field schema in the model -
     * protected $searchable_fields = [
     *  'id'   => ['type' => 'string', 'indexed' => true, 'stored' => true],
     *  'name' => ['type' => 'string', 'indexed' => true, 'stored' => true],
     * ]
     */
    'schema' => [
        'field_template' => [
            'name' => null,
            'type' => 'text_general',
            'indexed' => true,
            'stored' => true,
            //            'multiValued' => false,
        ]
    ]
];
