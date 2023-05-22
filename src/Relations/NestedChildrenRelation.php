<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\ElonestBuilder;
use Illuminate\Database\Eloquent\Builder;

class NestedChildrenRelation extends NodeRelation
{
    public bool $isNested = true;

    protected bool $hasMany = true;

    public function relatedQuery(ElonestBuilder $query): ?Builder
    {
        if (is_null($this->model->getLeftValue()) || is_null($this->model->getRightValue())) {
            return null;
        }
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
