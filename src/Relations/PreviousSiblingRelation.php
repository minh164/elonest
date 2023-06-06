<?php

namespace Minh164\EloNest\Relations;

class PreviousSiblingRelation extends NodeRelation
{
    /**
     * @inheritDoc
     * @return array[]
     */
    protected function relatedConditions(): array
    {
        return [
            [$this->model->getRightKey(), '=', $this->model->getLeftValue() - 1]
        ];
    }
}
