<?php

namespace Minh164\EloNest\Relations;

class NextSiblingRelation extends NodeRelation
{
    /**
     * @inheritDoc
     * @return array[]
     */
    protected function relatedConditions(): array
    {
        return [
            [$this->model->getLeftKey(), '=', $this->model->getRightValue() + 1]
        ];
    }
}
