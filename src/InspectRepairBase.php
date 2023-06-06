<?php

namespace Minh164\EloNest;

use Illuminate\Support\LazyCollection;
use Minh164\EloNest\Collections\NestedCollection;

/**
 * Layer handle actions about inspecting and repairing logics.
 */
class InspectRepairBase
{
    /**
     * Get lazy model set collection.
     * @param NestableModel $sampleModel
     * @param int $originalNumber
     * @return LazyCollection
     */
    public function getLazyModelsCollection(NestableModel $sampleModel, int $originalNumber): LazyCollection
    {
        return $sampleModel->newQuery()
            ->whereOriginalNumber($originalNumber)
            ->cursor();
    }

    /**
     * Get root node.
     *
     * @return null|array
     */
    protected function findRoot(): ?array
    {
        $root = $this->models->where($this->sampleModel->getParentKey(), $this->sampleModel->getRootNumber())->first();

        return $root ?? null;
    }

    /**
     * Count total of missing parents.
     *
     * @return array
     */
    protected function getMissingParents(): array
    {
        return $this->models->where($this->sampleModel->getParentKey(), '!=', $this->sampleModel->getRootNumber())->toArray();
    }
}
