<?php

declare(strict_types=1);

namespace Scout\Solr;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder;
use Solarium\Client as ClientBase;

class Client extends ClientBase implements ClientInterface
{
    public function setCore(Model|string $model): self
    {
        if(is_string($model))
        {
            $searchableAs = $model;
        }
        else
        {
            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $searchableAs = $model->searchableAs();
            
            if (is_array($searchableAs))
            {
                return $this->addEndpoint($searchableAs);
            }
        }
        $this->getEndpoint()->setCore($searchableAs);
        return $this;
    }
    
    public function search(Builder $builder)
    {
        $query = $this->createSelect();
        return $this->executeQuery($query, $builder);
    }
    
    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  \Closure|int|null  $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(Builder $builder, $perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $total = func_num_args() === 5 ? value(func_get_arg(4)) : $this->getCountForPagination();
        $perPage = $perPage instanceof Closure ? $perPage($total) : $perPage;
        
        $query = \Solr::createSelect();
        $offset = ($page - 1) * $perPage;
        return $this->executeQuery($query, $builder, $offset, $perPage);
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
    private function executeQuery(&$query, &$builder, $offset = 0, $limit = null)
    {
        $conditions = (!empty($builder->query))? [$builder->query] : [];
        foreach ($builder->wheres as $key => &$value)
            $conditions[] = "$key:\"$value\"";
        $query->setQuery(implode(' ', $conditions));
        if (!is_null($limit))
            $query->setStart($offset)->setRows($limit);
        return \Solr::select($query);
    }
    
    
    public function searchRaw($data = [])
    {
        
        $query = $this->createSelect();
        if(!empty($data)) {
            $search_string = '';
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $search_string .= $k . ':' . implode(' OR ', $v) . ', \n';
                }
                $search_string .= $k . ':' . $v . ', \n';
            }
            $query->setQuery($search_string);
        }
        $resultset = $this->execute($query);
        
        $docs = collect($resultset->getDocuments());
        $docs->transform(function ($item, $key) {
            $fields = $item->getFields();
            foreach ($fields as $k => $v) {
                if (is_array($v)) {
                    $fields[$k] = implode(', ', $v);
                }
            }
            
            return $fields;
        });
        
        return [
            'total' => $resultset->getNumFound(),
            'data' => $docs,
        ];
    }
    
    /**
     * Execute Update or Delete statement on the index.
     *
     * @throws \Exception In case of command failure.
     * @param $statement \Solarium\QueryType\Update\Query\Query
     */
    private function executeStatement(&$statement)
    {
        $statement->addCommit();
        $response = \Solr::update($statement);
        if ($response->getStatus() != 0)
            throw new \Exception("Update command failed \n\n".json_encode($response->getData()));
    }
    
}
