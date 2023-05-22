<?php

namespace Minh164\EloNest\Relations;

use Minh164\EloNest\ElonestBuilder;
use Illuminate\Database\Eloquent\Builder;

class PreviousSiblingRelation extends NodeRelation
{
    public function relatedQuery(ElonestBuilder $query): ?Builder
    {
        if (is_null($this->model->getLeftValue())) {
            return null;
        }
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
