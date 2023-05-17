<?php

namespace Scout\Solr;

use Illuminate\Database\Eloquent\Collection;
use Scout\Solr\Traits\HasSolrResults;

class SolrCollection extends Collection
{
    use HasSolrResults;

    public function total() {
        return $this->count();
    }
}
