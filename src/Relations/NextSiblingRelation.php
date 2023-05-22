<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\ElonestBuilder;
use Illuminate\Database\Eloquent\Builder;

class NextSiblingRelation extends NodeRelation
{
    public function relatedQuery(ElonestBuilder $query): ?Builder
    {
        if (is_null($this->model->getRightValue())) {
            return null;
        }
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
