<?php

namespace Scout\Solr\Traits;

ini_set('memory_limit','4096M');
set_time_limit(0);
ini_set('max_execution_time',0);

use Illuminate\Support\Arr;
use Scout\Solr\Builder;
use Laravel\Scout\Searchable as ScoutSearchable;
use Scout\Solr\SolrCollection;

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

    /* PR:  you will need this defined in the model class */
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

}
