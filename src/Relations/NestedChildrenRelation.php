<?php

namespace Minh164\EloNest\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\ElonestBuilder;
use Minh164\EloNest\NestableModel;
use Exception;

class NestedChildrenRelation extends NodeRelation
{
    public bool $isNested = true;

    protected bool $hasMany = true;

    /**
     * Depth number need to query.
     */
    protected ?int $depths;

    /**
     * @throws Exception
     */
    public function __construct(NestableModel $model, ?int $depths = null)
    {
        parent::__construct($model);
        $this->depths = $depths;
    }

    /**
     * @inheritDoc
     * @return array[]
     */
    protected function relatedConditions(): array
    {
        return [
            [$this->model->getLeftKey(), '>', $this->model->getLeftValue()],
            [$this->model->getRightKey(), '<', $this->model->getRightValue()],
        ];
    }

    /**
     * Override parent method.
     *
     * @inheritDoc
     * @param ElonestBuilder $query
     * @return Builder
     * @throws Exception
     */
    public function getQuery(ElonestBuilder $query): Builder
    {
        if (empty($query) || !count($this->relatedConditions()) || empty($this->model->getOriginalNumberValue())) {
            throw new Exception("relatedConditions() is null or Original Number is missing");
        }

        return $query
            ->whereBetween($this->model->getDepthKey(), [
                $this->model->getDepthValue(),
                $this->model->getDepthValue() + ($this->depths ?? $this->model->countDepths())
            ])
            ->where($this->relatedConditions())
            ->whereOriginalNumber($this->model->getOriginalNumberValue());
    }

    /**
     * @inheritDoc
     * @param ElonestCollection $mainNodes
     * @param ElonestCollection $allRelatedNodes
     * @param string $relationKey
     * @return ElonestCollection
     */
    public function mapRelationsToMains(ElonestCollection $mainNodes, ElonestCollection $allRelatedNodes, string $relationKey): ElonestCollection
    {
        /** @var NestableModel $main */
        foreach ($mainNodes as $main) {
            $relationsForMain = $allRelatedNodes
                ->where($main->getLeftKey(), '>', $main->getLeftValue())
                ->where($main->getRightKey(), '<', $main->getRightValue())
                ->sortBy($main->getLeftKey()); // Need sort by Left ASC.

            $this->nestChildrenForParent($main, $relationsForMain, $relationKey);
        }

        return $mainNodes;
    }

    /**
     * @param NestableModel $highestParent
     * @param ElonestCollection $children
     * @return NestableModel
     */
    public function nestChildrenForParent(NestableModel $highestParent, ElonestCollection $children, string $relationKey): NestableModel
    {
        $cloneHighestParent = clone $highestParent;
        $highestParent->setNodeRelations([$relationKey => []]);
        // Tracking for navigating to current nested parent.
        $nestedIndex = [0];
        $children = $children->values();
        /**
         * @var NestableModel|null $previous
         * @var NestableModel $current
         */
        foreach ($children as $key => $current) {
            try {
                if ($key == 0) {
                    $previous = $highestParent;
                } else {
                    $previous = $children->get($key - 1);
                }

                $current->setNodeRelations([$relationKey => null]);

                /* Current node is child of Previous node. */
                if ($current->getLeftValue() > $previous->getLeftValue() && $current->getRightValue() < $previous->getRightValue()) {
                    $parent = $this->getCurrentParent($nestedIndex, $highestParent, $relationKey);
                    $parent->setNodeRelations([$relationKey => new ElonestCollection([$current])]);

                    // Mark current parent index.
                    $nestedIndex[] = 0;
                    continue;
                }

                /* Current node will be sibling with Previous or Another node. */

                // Number depths of Current and Previous.
                $depthRanges = $current->getLeftValue() - $previous->getRightValue();
                $limitIndex = count($nestedIndex) - $depthRanges;
                $nestedIndex = array_slice($nestedIndex, 0, $limitIndex);

                // Add Current node to parent.
                $parent = $this->getCurrentParent($nestedIndex, $highestParent, $relationKey);
                /* @var ElonestCollection $relation */
                $relation = $parent->getNodeRelations()[$relationKey];
                $relation->push($current);

                // Mark current parent index.
                $nestedIndex[] = count($relation) - 1;
            } catch (\Exception $e) {
                Log::error("Something was wrong when nesting node ID {$current->getPrimaryId()}: " . $e->getMessage());
                return $cloneHighestParent;
            }
        }

        return $highestParent;
    }

    /**
     * @param array $nestedIndex
     * @param NestableModel $highestParent
     * @param string $relationKey
     * @return NestableModel
     */
    private function getCurrentParent(array $nestedIndex, NestableModel $highestParent, string $relationKey): NestableModel
    {
        $parent = $highestParent;
        foreach ($nestedIndex as $key => $index) {
            if ($key == 0) {
                $parent = $highestParent;
            } else {
                $parent = $parent->getNodeRelations()[$relationKey]->get($index);
            }
        }

        return $parent;
    }
}
