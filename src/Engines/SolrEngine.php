<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Scout\Solr\Engines;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Scout\Solr\Client;
use Scout\Solr\ClientInterface;
use Scout\Solr\Events\AfterSelect;
use Scout\Solr\Events\BeforeSelect;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Result\Document;
use Solarium\QueryType\Select\Result\Result;

/**
 * @mixin Client
 */
class SolrEngine extends Engine
{
    public const NESTED_QUERY = '_nested_';
    public const SIMPLE_QUERY = '_simple_';
    public const NESTED_QUERY_SEPARATOR = '_';
    
    private ClientInterface $client;
    private Repository $config;
    private Dispatcher $events;
    
    /**
     * Access to the query helper.
     *
     * @var Helper
     */
    private $helper;
    
    /**
     * Store the last result created allowing us to attach it to collection results.
     *
     * @var \Solarium\QueryType\Select\Result\Result
     */
    private $lastSelectResult;
    
    /**
     * Make the key for the meta values in the searchable array configurable.
     *
     * @var string
     */
    private $metaKey = null;
    
    public function __construct(ClientInterface $client, Repository $config, Dispatcher $events)
    {
        $this->client = $client;
        $this->helper = $client->createSelect()->getHelper();
        $this->config = $config;
        $this->events = $events;
        $this->metaKey = $config->get('scout-solr.meta_key', 'meta');
    }
    
    /**
     * Execute Update or Delete statement on the index.
     *
     * @throws \Exception In case of command failure.
     * @param $statement \Solarium\QueryType\Update\Query\Query
     */
    private function executeStatement(&$statement){
        $statement->addCommit();
        $response = $this->client->update($statement);
        if($response->getStatus() != 0)
            throw new \Exception("Update command failed \n\n".json_encode($response->getData()));
    }
    
    
    /**
     * Execute Select command on the index.
     *
     * @param \Solarium\QueryType\Select\Query\Query $query
     * @param \Laravel\Scout\Builder $builder
     * @param int $offset
     * @param int $limit
     * @return \Solarium\QueryType\Select\Result\Result
     */
    private function executeQuery(&$query, &$builder, $offset = 0, $limit = null) {
        
        
        $conditions = (!empty($builder->query))? $builder->query : '';
        if(!is_array($conditions)) {
            $conditions = [$conditions];
        }
        
        
        foreach($builder->wheres as $key => &$value)
        {
            $conditions[] = "{$key}:\"{$value}\"";
        }
        
        
        $query->setQuery(implode(' ', $conditions));
        
        if(!is_null($limit))
            $query->setStart($offset)->setRows($limit);
        return $this->client->select($query);
    }
    
    /**
     * Actually perform the search, allows for options to be passed like pagination.
     *
     * @param Builder|BaseBuilder $builder The query builder we were passed
     * @param array $options An array of options to use to do things like pagination, faceting?
     *
     * @return \Solarium\Core\Query\Result\Result The results of the query
     */
    protected function performSearch($builder, array $options = [])
    {
        
        
        if (!($builder instanceof Builder)) {
            throw new \Exception(
                'Your model must use the Scout\\Solr\\Searchable trait in place of Laravel\\Scout\\Searchable'
            );
        }
        
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->client,
                $builder->query,
                $options
            );
        }
        
        $this->client->setCore($builder->model);
        
        $endpoint = $builder->model->searchableAs();
        // build the query string for the q parameter
        if (is_array($builder->query)) {
            $queryString = collect($builder->query)
                ->map(function ($item, $key) {
                    if (is_array($item)) {
                        // there are multiple search queries for this term
                        $query = [];
                        foreach ($item as $query) {
                            $query[] = is_numeric($key) ? $query : "$key:$query";
                        }
                        
                        return implode(' ', $query);
                    } else {
                        return is_numeric($key) ? $item : "$key:$item";
                    }
                })
                ->filter()
                ->implode(' ');
        } else {
            $queryString = $builder->query;
        }
        $query = $this->client->createSelect();
        if ($builder->isEDismax()) {
            $dismax = $query->getEDisMax();
        } elseif ($builder->isDismax()) {
            $dismax = $query->getDisMax();
        }
        
        if (isset($dismax) && empty($queryString)) {
            $dismax->setQueryAlternative('*:*');
        }
        $query->setQuery($queryString);
        
        // get the filter query
        $filters = [];
        // loop through and merge any `OR` queries into one
        
        foreach ($builder->wheres as $where) {
            if ($where['type'] === static::NESTED_QUERY) {
                $queryString = $this->compileNestedQuery($where);
            } elseif ($where['type'] === static::SIMPLE_QUERY) {
                $where_bindings = $where['bindings'];
                if(!is_array($where_bindings))
                {
                    $where_bindings = [$where_bindings];
                }
                $where_query = $where['query'];
                if(!strstr($where_query, '%')) {
                    $where_query .= ':%1%';
                }
                $queryString = $this->helper->assemble($where_query, $where_bindings);
            }
            if (!empty($filters) && $where['boolean'] === 'OR') {
                $previous = array_pop($filters);
                $queryString = "{$previous} OR ({$queryString})";
            }
            $filters[] = $queryString;
        }
        
        
        collect($filters)->each(function (string $fq) use ($query) {
            $query->createFilterQuery(md5($fq))->setQuery($fq);
        });
        // build any faceting
        $facetSet = $query->getFacetSet();
        $facetSet->setOptions($builder->facetOptions);
        if (!empty($builder->facetFields)) {
            foreach ($builder->facetFields as $field) {
                $facetSet->createFacetField("$field-field")->setField($field);
            }
        }
        if (!empty($builder->facetQueries)) {
            foreach ($builder->facetQueries as $field => $queries) {
                if (count($queries) > 1) {
                    $facet = $facetSet->createFacetMultiQuery("$field-multiquery");
                    foreach ($queries as $i => $fQuery) {
                        $facet->createQuery("$field-multiquery-$i", $fQuery);
                    }
                } else {
                    $facetSet->createFacetQuery("$field-query")->setQuery("$field:{$queries[0]}");
                }
            }
        }
        if (!empty($builder->facetPivots)) {
            foreach ($builder->facetPivots as $fields) {
                $facetSet
                    ->createFacetPivot(implode('-', $fields))
                    ->addFields(implode(',', $fields));
            }
        }
        
        // set up spellchecking
        if ($builder->getUseSpellcheck()) {
            $spellcheck = $query->getSpellcheck();
            $spellcheck->setOptions($builder->getSpellcheckOptions());
        }
        
        // Set the boost fields
        if (isset($dismax) && $builder->hasBoosts()) {
            $dismax->setQueryFields($builder->getBoosts());
        }
        
        // allow for pagination here
        if ($builder->getStart() !== null) {
            $query->setStart($builder->getStart());
        } elseif (array_key_exists('start', $options)) {
            $query->setStart($options['start']);
        }
        // add ordering to the search
        if ($builder->orders) {
            foreach ($builder->orders as $sort) {
                $query->addSort($sort['column'], $sort['direction']);
            }
        }
        // if a row limit is set, include that
        if ($builder->limit) {
            $query->setRows($builder->limit);
        }
        
        $this->events->dispatch(new BeforeSelect($query, $builder));
        $this->lastSelectResult = $this->client->select($query);
        //dd(__METHOD__ . ' Line: ' . __LINE__, 'QUERY:', $query, 'RES:', $this->lastSelectResult);
        $this->events->dispatch(new AfterSelect($this->lastSelectResult, $builder->model));
        return $this->lastSelectResult;
    }
    
    
    /**
     * Takes a nested set of queries and turns it into a single query string (pre-compiled)
     *
     * @param array $where The nested query array to compile
     * @return string
     */
    public function compileNestedQuery($where)
    {
        $first = true;
        $query = '';
        foreach ($where['queries'] as $subWhere) {
            if ($subWhere['type'] === static::NESTED_QUERY) {
                $queryPart = call_user_func([$this, 'compileNestedQuery'], $subWhere);
            } else {
                $sub_where_bindings = $subWhere['bindings'];
                if(!is_array($sub_where_bindings))
                {
                    $sub_where_bindings = [$sub_where_bindings];
                }
                $sub_where_query = $subWhere['query'];
                if(!strstr($sub_where_query, '%')) {
                    $sub_where_query .= ':%1%';
                }
                $queryPart = $this->helper->assemble($sub_where_query, $sub_where_bindings);
            }
            if (!$first) {
                $query .= " {$subWhere['boolean']} $queryPart";
            } else {
                $query = $queryPart;
                $first = false;
            }
        }
        return $query;
    }
    
    
    
    
    
    public function update($models): ResultInterface
    {
        $this->client->setCore($models->first());
        
        $update = $this->client->createUpdate();
        $documents = $models->map(static function (Model $model) use ($update) {
            
            if (empty($searchableData = $model->toSearchableArray())) {
                /** @noinspection PhpInconsistentReturnPointsInspection */
                return;
            }
            
            return $update->createDocument(
                array_merge($searchableData, $model->scoutMetadata())
            );
            
        })->filter()->values()->all();
        
        return $this->client->update($update, $this->getEndpointFromConfig($models->first()->searchableAs()));
    }
    
    public function delete($models): void
    {
        $this->client->setCore($models->first());
        
        $delete = $this->client->createUpdate();
        $delete->addDeleteByIds(
            $models->map->getScoutKey()
                ->values()
                ->all()
        );
        $delete->addCommit();
        
        $this->client->update($delete, $this->getEndpointFromConfig($models->first()->searchableAs()));
    }
    
    
    
    public function search(Builder $builder){
        return $this->performSearch($builder);
    }
    
    
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @param  int $perPage
     * @param  int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page){
        //decrement the page number as we're actually dealing with an offset, not page number
        $page--;
        
        $builder->take($perPage);
        
        return $this->performSearch($builder, [
            'start' => $page * $perPage,
        ]);
    }
    
    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  \Solarium\QueryType\Select\Result\Result $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        // how do we get the pk without a model?
        return collect($results)
            ->pluck('id')
            ->values();
    }
    
    /**
     * @param Builder $builder
     * @param Result $results
     * @param Model $model
     * @return Collection|void
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results->getNumFound() === 0) {
            return $model->newCollection();
        }
        
        $objectIds = collect($results->getDocuments())->map(static function (Document $document) {
            return $document->getFields()['id'];
        })->values()->all();
        $objectIdPositions = array_flip($objectIds);
        
        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }
    
    /**
     * @param Builder $builder
     * @param Result $results
     * @param Model $model
     * @return LazyCollection|void
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if ($results->getNumFound() === 0) {
            return LazyCollection::make($model->newCollection());
        }
        
        $objectIds = collect($results->getDocuments())->map(static function (Document $document) {
            return $document->getFields()['id'];
        })->values()->all();
        $objectIdPositions = array_flip($objectIds);
        
        return $model->getScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }
    
    /**
     * @param Result $results
     * @return int
     */
    public function getTotalCount($results): int
    {
        return $results->getNumFound();
    }
    
    public function flush($model): void
    {
        $query = $this->client->setCore(new $model())->createUpdate();
        $query->addDeleteQuery('*:*');
        $query->addCommit();
        
        $this->client->update($query, $this->getEndpointFromConfig($model->searchableAs()));
    }
    
    public function createIndex($name, array $options = [])
    {
        $coreAdminQuery = $this->client->setCore($name)->createCoreAdmin();
        $action = $coreAdminQuery->createCreate();
        $action->setConfigSet($this->config->get('scout-solr.create.config_set'));
        $action->setCore($name);
        $action->setInstanceDir($name);
        $coreAdminQuery->setAction($action);
        return $this->client->coreAdmin($coreAdminQuery);
    }
    
    public function deleteIndex($name)
    {
        $coreAdminQuery = $this->client->createCoreAdmin();
        $this->client->getEndpoint()->setCore($name);
        $action = $coreAdminQuery->createUnload();
        $action->setCore($name);
        $action->setDeleteIndex($this->config->get('scout-solr.unload.delete_index'));
        $action->setDeleteDataDir($this->config->get('scout-solr.unload.delete_data_dir'));
        $action->setDeleteInstanceDir($this->config->get('scout-solr.unload.delete_instance_dir'));
        
        $coreAdminQuery->setAction($action);
        return $this->client->coreAdmin($coreAdminQuery, $this->getEndpointFromConfig($name));
    }

//    protected function performSearch(Builder $builder, array $options = []): Result
//    {
//        $this->client->setCore($builder->model);
//
//        if ($builder->callback) {
//            return call_user_func(
//                $builder->callback,
//                $this->client,
//                $builder->query,
//                $options
//            );
//        }
//
//        $query = $this->client->createSelect();
//        if (array_key_exists('filters', $options)) {
//            $query->setQuery($options['filters']);
//        } else {
//            $query->setQuery($builder->query);
//        }
//
//        foreach ($builder->orders as $order) {
//            $query->addSort($order['column'], $order['direction']);
//        }
//
//        $query->setStart($options['offset'] ?? 0)
//            ->setRows($options['limit'] ?? $this->config->get('scout-solr.select.limit'));
//
//        $this->events->dispatch(new BeforeSelect($query, $builder));
//
//        $result = $this->client->select($query, $this->getEndpointFromConfig($builder->model->searchableAs()));
//
//        $this->events->dispatch(new AfterSelect($result, $builder->model));
//
//        return $result;
//    }
    
    protected function filters(Builder $builder): string
    {
        $filters = collect($builder->wheres)->map(function ($value, $key) {
            return sprintf('%s:%s', $key, $value);
        });
        
        foreach ($builder->whereIns as $key => $values) {
            $filters->push(sprintf('%s:(%s)', $key, collect($values)->map(function ($value) {
                return $value;
            })->values()->implode(' OR ')));
        }
        
        return $filters->values()->implode(' AND ');
    }
    
    public function getEndpointFromConfig(string $name): ?Endpoint
    {
        if ($this->config->get('scout-solr.endpoints.' . $name) === null) {
            return null;
        }
        
        return new Endpoint($this->config->get('scout-solr.endpoints.' . $name));
    }
    
    /**
     * Dynamically call the Solr client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->client->$method(...$parameters);
    }
}
