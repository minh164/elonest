<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\NodeBuilder;
use Illuminate\Database\Eloquent\Builder;

class PreviousSiblingRelation extends NodeRelation
{
    public const RELATION_KEY = 'prevSibling';

    public function execute(): mixed
    {
        return $this->getQuery($this->model->newQuery())->first();
    }

    public function getQuery(NodeBuilder $query): Builder
    {
        return $query->wherePrevSibling($this->model->getLeftValue());
    }

    public function getMapping(): MappingInfo
    {
        return new MappingInfo(
            $this->model->getRightKey(),
            '=',
            [$this->model->getLeftKey(), '-', 1]
        );
    }
}
