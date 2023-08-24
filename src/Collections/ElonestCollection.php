<?php

namespace Minh164\EloNest\Collections;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\NestableModel;

/**
 * Layer data about nested set model collection.
 */
class ElonestCollection extends EloquentCollection
{
    /**
     * Load a set of node relationships onto the collection.
     *
     * @param mixed $relations
     * @return $this
     * @throws Exception
     */
    public function loadNodeRelations(mixed $relations): static
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $model = $this->first();
            if (!$model instanceof NestableModel) {
                throw new Exception('Object is not a ' . NestableModel::class . ' instance');
            }

            $query = $model->newQueryWithoutScopes()->withNodes($relations);
            $query->eagerLoadNodeRelations($this);
        }

        return $this;
    }

    /**
     * Filter only get sibling list.
     *
     * @return $this
     * @throws ElonestException
     */
    public function onlySiblings(): static
    {
        if ($this->isEmpty()) {
            return $this->values();
        }

        $sampleModel = $this->getSampleModel();

        /* @var ElonestCollection $collection */
        $collection = $this->sortBy($sampleModel->getLeftKey())->values();

        /* @var NestableModel $firstSibling */
        $firstSibling = $collection->first();

        /* @var NestableModel $item */
        foreach ($collection as $index => $item) {
            // Bypass first sibling.
            if ($index == 0) {
                continue;
            }

            // If item is NOT a sibling of first item, then remove it from collection.
            if (! $item->isSiblingOf($firstSibling)) {
                $collection->forget($index);
            }
        }

        return $collection->values();
    }

    /**
     * Return a list without children of each item (ONLY get item and remove its children).
     *
     * @return $this
     * @throws ElonestException
     */
    public function excludeChildren(): static
    {
        if ($this->isEmpty()) {
            return $this->values();
        }

        $sampleModel = $this->getSampleModel();

        /* @var ElonestCollection $collection */
        $collection = $this->sortBy($sampleModel->getLeftKey())->values();

        $currentParent = null;

        /* @var NestableModel $item */
        foreach ($collection as $index => $item) {
            if ($index == 0) {
                $currentParent = $item;
                continue;
            }

            if ($item->isChildOf($currentParent)) {
                // If item is a child of current parent, then remove it from collection (ONLY get its parent).
                $collection->forget($index);
            } else {
                // Otherwise, keep on remove children of item.
                $currentParent = $item;
            }
        }

        return $collection->values();
    }

    /**
     * Get sample model from collection.
     *
     * @return NestableModel
     * @throws ElonestException
     */
    private function getSampleModel(): NestableModel
    {
        if (! $sampleModel = $this->first()) {
            throw new ElonestException("Collection does not have Nestable Model");
        }

        return $sampleModel;
    }
}
