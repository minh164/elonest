<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\NodeBuilder;
use Illuminate\Database\Eloquent\Builder;

class NextSiblingRelation extends NodeRelation
{
    public const RELATION_KEY = 'nextSibling';

    public function execute(): mixed
    {
        return $this->getQuery($this->model->newQuery())->first();
    }

    public function getQuery(NodeBuilder $query): Builder
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
