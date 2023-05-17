<?php

namespace Scout\Solr\Traits;

ini_set('memory_limit','4096M');
set_time_limit(0);
ini_set('max_execution_time',0);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Scout\Solr\Builder;
use Laravel\Scout\Searchable as ScoutSearchable;
use Scout\Solr\ClientInterface;
use Scout\Solr\SolrCollection;
use Solarium\Core\Query\Result\ResultInterface;

trait Searchable
{
    use ScoutSearchable {
        ScoutSearchable::searchableAs as parentSearchableAs;
    }
    /**
     * Additional metadata attributes managed by Scout.
     *
     * @var array
     */
    protected       $scoutMetadata = [];
    public          $min_ngrams = 3;
    public          $max_ngrams = 8;
    
    /*
     * PR:  you will need this defined in the model class
    
    Example:
    protected $searchable_fields = [
        'id'            => ['type' => 'string', 'indexed' => true, 'stored' => true],
        'role_id'       => ['type' => 'plong', 'indexed' => true, 'stored' => true],
        'name'          => ['type' => 'string', 'indexed' => true, 'stored' => true],
        'description'   => ['type' => 'text_general', 'indexed' => true, 'stored' => true],
        'is_active'     => ['type' => 'boolean', 'indexed' => true, 'stored' => true],
        'created_at'    => ['type' => 'pdate', 'indexed' => true, 'stored' => true],
        'updated_at'    => ['type' => 'pdate', 'indexed' => true, 'stored' => true],
    ];
    */
    //protected $searchable_fields = ['id', 'uid'];
    protected $searchable_as = '';
    /*
     * Array of attributes for search indexing to sort and filter on
     */
    
    
    /**
     * Generates Eloquent attributes to Solr fields mapping.
     *
     * @return array
     */
    public function getScoutMap(){
        return array_combine($this->attributes, $this->attributes);
    }
    
    public function getSortableAttributes()     { return $this->sortableAttributes;     }
    public function getFilterableAttributes()   { return $this->filterableAttributes;   }
    
    
    /**
     * override as needed.  set the index/collection/core in the scout search engine
     * @return string
     */
    public function searchableAs(): string
    {
        if(!empty($this->searchable_as)) {
            return $this->searchable_as;
        }
        return $this->parentSearchableAs();
    }
    
    /*
     * PR: Custom overrides and methods
     */
    public function getSearchableFields() {
        return $this->searchable_fields;
    }
    public function customSearchData() {
        return [];
    }
    
    
    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray(): array
    {
        
        $array = [];
        
        $searchable_fields = $this->getSearchableFields();
        
        
        if(!empty($searchable_fields))
        {
            foreach ($searchable_fields as $k => $field)
            {
                /*
                 * for future use..  setting a field as
                 * 'description' => ['indexed' => false, 'stored' => true],
                 * will allow us to figure out what fields to index and what fields to only store
                 * in a SOLR engine.
                 */
                if(is_array($field)) {
                    $options = $field; // future use
                    $field = $k;
                }
                $val = $this->getAttribute($field);
                /* check if $val is carbon or date object */
                if(is_object($val) && method_exists($val, 'toDateTimeString')) {
                    $val = $val->toDateTimeString();
                }
                
                $array[ $field ] = $val;
            }
        }
        else
        {
            $array = $this->toArray();
        }
        
        $array = array_merge($array,$this->customSearchData());
        
        /*
         * !!REQUIRED!! we need 'id' to be in the array for the search to work
         */
        if(!array_key_exists($this->primaryKey, $array))
        {
            $array[$this->primaryKey] = $this->getAttribute($this->primaryKey);
        }
        
        
        return $array;
    }
    
    public function buildTrigrams($array)
    {
        foreach ($array as $k => $v)
        {
            if(is_array($v)) {
                $array = Arr::dot($v);
                foreach ($array as $key => $val) {
                    $array["{$key}Ngrams"] = utf8_encode($this->_buildTrigrams((string)$val));
                }
            }
            else
            {
                $array["{$k}Ngrams"] = utf8_encode($this->_buildTrigrams((string)$v));
            }
        }
        return $array;
    }
    
    /**
     * @param $keyword
     *
     * @return string
     */
    public function _buildTrigrams($keyword)
    {
        $t        = "__".$keyword."__";
        $trigrams = "";
        for ($i = 0; $i < strlen($t) - 2; $i++) {
            $trigrams .= mb_substr($t, $i, 3)." ";
        }
        
        return trim($trigrams);
    }
    
    
    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Scout\Solr\Builder
     */
    public static function search($query = '*:*', $callback = null)
    {
        return app(Builder::class, [
            'model' => new static,
            'query' => $query,
            'callback' => $callback,
            'softDelete'=> static::usesSoftDelete() && config('scout.soft_delete', false),
        ]);
    }
    
    /**
     * Give the model access to the solr query escaper for terms.
     *
     * @param string $query
     * @return string
     */
    public static function escapeSolrQueryAsTerm(string $query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsTerm($query);
    }
    
    /**
     * Give the model access to the solr query escaper for phrases.
     *
     * @param string $query
     * @return string
     */
    public static function escapeSolrQueryAsPhrase(string $query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsPhrase($query);
    }
    
    /**
     * Override the newCollection method on Model to use the SolrCollection class if no collection is set by the Model.
     *
     * @param array $models
     * @return SolrCollection
     */
    public function newCollection(array $models = [])
    {
        return new SolrCollection($models);
    }
    
    public function buildSolrSchema() {
        $fields = collect($this->getSearchableFields());
        $field_template = config('scout-solr.schema.field_template');
        $fields->transform(function($field, $key) use ($field_template) {
            if(!data_get($field, 'name')) {
                $name = $key;
                data_set($field, 'name', $name);
            }
            return array_merge($field_template, $field);
        });
        foreach ($fields as $field) {
            
            try {
                $res = $this->addField($this, $field);
            }
            catch (\Solarium\Exception\HttpException $e) {
                
                Log::error(sprintf('[%s][%s] Error adding field: %s',
                    __METHOD__,
                    __LINE__,
                    $e->getMessage()
                ));
                // Error adding field for model (we assume it means it already exists) so trying to edit instead',
                try {
                    $res = $this->replaceField($this, $field);
                }
                catch (\Solarium\Exception\HttpException $e) {
                    Log::error(sprintf('[%s][%s] Error editing field: %s',
                        __METHOD__,
                        __LINE__,
                        $e->getMessage()
                    ));
                }
            }
            Log::info(sprintf('Processed field %s for model %s',
                $field['name'],
                get_class($this),
            ));
        }
    }
    
    public function getClient($model) {
        $client = app()->make(ClientInterface::class);
        $client->setCore($model);
        return $client;
    }
    
    /**
     * reusable method to get a Solr API query object
     *
     * @param       $client
     * @param Model $model
     * @return Solarium\QueryType\Server\Api\Query
     */
    public function getQuery($client, Model $model) {
        /** @var Solarium\QueryType\Server\Api\Query  $query */
        $query = $client->createApi();
        $query->setHandler($model->searchableAs() . '/schema');
        $query->setMethod('POST');
        return $query;
    }
    
    
    /**
     * Triggers an add field call on Solr
     * @param Model $model
     * @param array $field
     * @return ResultInterface
     */
    public function addField(Model $model, Array $field) {
        $client = $this->getClient($model);
        $query  = $this->getQuery($client, $model);
        
        $query->setRawData(json_encode([
            'add-field' => $field,
        ]));
        
        return $client->execute($query);
    }
    
    /**
     * trigger a replace field call on Solr
     *
     * @param Model $model
     * @param array $field
     * @return ResultInterface
     */
    public function replaceField(Model $model, Array $field) {
        $client = $this->getClient($model);
        $query  = $this->getQuery($client, $model);
        
        $query->setRawData(json_encode([
            'replace-field' => $field,
        ]));
        
        return $client->execute($query);
    }
    
}
