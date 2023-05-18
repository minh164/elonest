<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\ElonestBuilder;
use Illuminate\Database\Eloquent\Builder;

class NextSiblingRelation extends NodeRelation
{
    public function execute(): mixed
    {
        return $this->getQuery($this->model->newQuery())->first();
    }

    public function getQuery(ElonestBuilder $query): Builder
    {
        return $query->whereNextSibling($this->model->getRightValue());
    }

    public function getMapping(): MappingInfo
    {
        return new MappingInfo(
            $this->model->getLeftKey(),
            '=',
            [$this->model->getRightKey(), '+', 1]
        );
    }
}
