<?php

declare(strict_types=1);

namespace Scout\Solr;

use Illuminate\Database\Eloquent\Model;
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
}
