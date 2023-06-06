<?php

namespace Minh164\EloNest\Relations;

class ParentsRelation extends NodeRelation
{
    protected bool $hasMany = true;

    /**
     * @inheritDoc
     * @return array[]
     */
    protected function relatedConditions(): array
    {
        return [
            [$this->model->getLeftKey(), '<', $this->model->getLeftValue()],
            [$this->model->getRightKey(), '>', $this->model->getRightValue()],
        ];
    }
}
