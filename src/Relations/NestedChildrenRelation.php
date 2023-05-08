<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\NodeBuilder;
use Illuminate\Database\Eloquent\Builder;

class NestedChildrenRelation extends NodeRelation
{
    public bool $isNested = true;

    protected bool $hasMany = true;

    public function execute(): mixed
    {
        return $this->getQuery($this->model->newQuery())->get();
    }

    public function getQuery(NodeBuilder $query): Builder
    {
        return $query->whereChildren($this->model->getLeftValue(), $this->model->getRightValue());
    }

    public function getMapping(): MappingInfo
    {
        return new MappingInfo(
            $this->model->getParentKey(),
            '=',
            [$this->model->getPrimaryName()]
        );
    }
}
