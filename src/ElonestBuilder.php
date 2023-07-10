<?php

namespace Minh164\EloNest;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\Relations\NodeRelation;

/**
 * Query builder with nestable model.
 */
class ElonestBuilder extends Builder
{
    /**
     * @inheritdoc
     *
     * @var NestableModel
     */
    protected $model;

    /**
     * Node relations.
     *
     * @var array
     */
    protected array $withNodes = [];

    /**
     *
     * @param string|array $relations
     *
     * @return $this
     */
    public function withNodes(mixed $relations): static
    {
        $relationArray = is_string($relations) ? func_get_args() : $relations;
        foreach ($relationArray as $relation) {
            $this->withNodes[] = $relation;
        }

        return $this;
    }

    /**
     * Determines has node relations.
     *
     * @return bool
     */
    protected function hasNodeRelations(): bool
    {
        return !empty($this->withNodes);
    }

    /**
     * Get nodes with nested relations.
     *
     * @inheritDoc
     * @param array|string $columns
     * @return ElonestCollection
     * @throws Exception
     */
    public function get($columns = ['*'], bool $ignoreRelations = false): ElonestCollection
    {
        /** @var ElonestCollection $mainNodes */
        $mainNodes = parent::get($columns);
        if ($ignoreRelations) {
            return $mainNodes;
        }

        return $this->eagerLoadNodeRelations($mainNodes);
    }

    /**
     * First node with nested relations.
     *
     * @inheritDoc
     * @param array|string $columns
     * @return NestableModel|null
     * @throws Exception
     */
    public function first($columns = ['*'], bool $ignoreRelations = false): ?NestableModel
    {
        $node = parent::first($columns);

        if ($node && !$ignoreRelations) {
            $nodesWithRelations = $this->eagerLoadNodeRelations(new ElonestCollection([$node]));
            $node = $nodesWithRelations->first();
        }

        return $node;
    }

    /**
     * Find and set relations for main node models.
     *
     * @param ElonestCollection $mainNodes Node collection which set relations
     *
     * @return ElonestCollection
     *
     * @throws Exception
     */
    public function eagerLoadNodeRelations(ElonestCollection $mainNodes): ElonestCollection
    {
        if ($this->hasNodeRelations()) {
            foreach ($this->withNodes as $relation) {
                // Extract key and params.
                $keyAndParams = explode(':', $relation);
                $key = $keyAndParams[0] ?? null;
                unset($keyAndParams[0]);
                $params = $keyAndParams ?? null;

                // Check relation is existed in model.
                if (!method_exists($this->model::class, $key)) {
                    throw new ElonestException("$key relation is not existed");
                }

                // Check relation is NodeRelation instance.
                //if (!$this->model->$key() instanceof NodeRelationBuilder) {
                //    throw new Exception("$key is not a " . NodeRelationBuilder::class . " instance");
                //}

                $relationNodes = $this->getRelatedNodes($mainNodes, $key, $params);

                /* @var NodeRelationBuilder $relationBuilderSample */
                $relationBuilderSample = $this->model->$key();
                $nodeRelationInstance = $relationBuilderSample->getNodeRelationInstance();
                if (! $nodeRelationInstance) {
                    throw new ElonestException("$key doesn't have a " . NodeRelation::class . " instance");
                }

                $mainNodes = $nodeRelationInstance->mapRelationsToMains($mainNodes, $relationNodes, $key);
            }
        }

        return $mainNodes;
    }

    /**
     * Get all related nodes of main nodes.
     *
     * @param ElonestCollection $mainNodes Main node collection
     * @param string $relation Relation key in model
     * @param array|null $params Params of relation method
     * @return ElonestCollection
     * @throws Exception
     */
    protected function getRelatedNodes(ElonestCollection $mainNodes, string $relation, ?array $params = null): ElonestCollection
    {
        $relatedQuery = $this->model->newQueryWithoutScopes();

        $isNull = false;
        $relatedQuery->where(function (ElonestBuilder $query) use ($mainNodes, $relation, &$isNull, $params) {
            /* @var NestableModel $mainNode */
            foreach ($mainNodes as $mainNode) {
                try {
                    /* @var NodeRelationBuilder $nodeRelationBuilder */
                    if ($params) {
                        $nodeRelationBuilder = $mainNode->$relation(...$params);
                    } else {
                        $nodeRelationBuilder = $mainNode->$relation();
                    }

                    $query->orWhere(function (ElonestBuilder $query) use ($nodeRelationBuilder) {
                        $query->addNestedWhereQuery($nodeRelationBuilder->getQuery());
                    });
                    $isNull = true;
                } catch (ElonestException $e) {
                    continue;
                }
            }
        });

        if (!$isNull) {
            return new ElonestCollection([]);
        }
        return $relatedQuery->get(['*'], true);
    }



    /**
     * All children of node query.
     *
     * @param int $parentLeft Parent's left value
     * @param int $parentRight Parent's right value
     *
     * @return $this
     */
    public function whereChildren(int $parentLeft, int $parentRight): static
    {
        $this->where(function (ElonestBuilder $query) use ($parentLeft, $parentRight) {
            $query
                ->where($this->model->getLeftKey(), '>', $parentLeft)
                ->where($this->model->getRightKey(), '<', $parentRight);
        });

        return $this;
    }

    /**
     * Node and it's children query.
     *
     * @param int $parentLeft Parent's left value
     * @param int $parentRight Parent's right value
     *
     * @return $this
     */
    public function whereNodeAndChildren(int $parentLeft, int $parentRight): static
    {
        $this->where(function (ElonestBuilder $query) use ($parentLeft, $parentRight) {
            $query
                ->where($this->model->getLeftKey(), '>=', $parentLeft)
                ->where($this->model->getRightKey(), '<=', $parentRight);
        });

        return $this;
    }

    /**
     * Excluding node and it's children query.
     *
     * @param int $parentLeft Parent's left value
     * @param int $parentRight Parent's right value
     *
     * @return $this
     */
    public function whereNotNodeAndChildren(int $parentLeft, int $parentRight): static
    {
        $this->where(function (ElonestBuilder $query) use ($parentLeft, $parentRight) {
            $query
                ->where($this->model->getLeftKey(), '<', $parentLeft)
                ->orWhere($this->model->getRightKey(), '>', $parentRight);
        });

        return $this;
    }

    /**
     * Set equal original number query.
     *
     * @param int $originalNumber Original number need to be queried
     * @return $this
     */
    public function whereOriginalNumber(int $originalNumber): static
    {
        $this->where($this->model->getOriginalNumberKey(), $originalNumber);

        return $this;
    }

    /**
     * Root node query.
     *
     * @return $this
     */
    public function whereRoot(): static
    {
        $this->where($this->model->getParentKey(), $this->model->getRootNumber());

        return $this;
    }

    /**
     * Previous sibling (left side) query.
     *
     * @param int $nodeLeft Left value of node
     *
     * @return $this
     */
    public function wherePrevSibling(int $nodeLeft): static
    {
        $this->where($this->model->getRightKey(), '=', $nodeLeft - 1);

        return $this;
    }

    /**
     * Next sibling (right side) query.
     *
     * @param int $nodeRight Right value of node
     *
     * @return $this
     */
    public function whereNextSibling(int $nodeRight): static
    {
        $this->where($this->model->getLeftKey(), '=', $nodeRight + 1);

        return $this;
    }

    /**
     * Determines object is a Nestable model.
     *
     * @param mixed $object Object want to check
     *
     * @return bool
     *
     * @throws Exception
     */
    protected function isNestableModel(mixed $object): bool
    {
        if (!$object instanceof NestableModel) {
            throw new Exception('Object is not a ' . NestableModel::class . ' instance');
        }

        return true;
    }

    /**
     * Create new node.
     *
     * @param array $insert Attribute data
     * @param int|null $parentId ID of parent
     *
     * @return NestableModel
     * @throws Exception
     */
    public function createNode(array $insert, int $parentId = null): NestableModel
    {
        /* @var NestableModel $node*/
        $node = $this->newModelInstance($insert);
        $node->setParentId($parentId);

        // Use save model to fire "creating" event.
        $node->save();

        return $node;
    }

    /**
     * @param int $originalNumber
     * @param array $insert
     * @return NestableModel
     * @throws Exception
     */
    protected function createRootNode(int $originalNumber, array $insert): NestableModel
    {
        /* @var NestableModel $model */
        $model = $this->model;

        $insert[$model->getLeftKey()] = 1;
        $insert[$model->getRightKey()] = 2;
        $insert[$model->getOriginalNumberKey()] = $originalNumber;

        // Use this if roots need chain together.
        //$this->chainLatestRoot($model, $insert);

        /* @var NestableModel $node*/
        $node = $this->newModelInstance($insert);
        $node->saveQuietly();

        return $node;
    }

    /**
     * Set left and right values are chained with previous root.
     *
     * @param NestableModel $model
     * @param array $insertData
     * @return void
     * @throws Exception
     */
    protected function chainLatestRoot(NestableModel $model, array &$insertData): void
    {
        /* @var static $builder */
        $builder = $model->newInstance()->newQueryWithoutScopes();

        /* @var NestableModel $latestRoot */
        $latestRoot = $builder
            ->where($model->getParentKey(), 0)
            ->orderBy($model->getRightKey(), 'DESC')
            ->first();

        if ($latestRoot) {
            $insert[$model->getLeftKey()] = $latestRoot->getRightValue() + 1;
            $insert[$model->getRightKey()] = $latestRoot->getRightValue() + 2;
        }
    }

    /**
     * First root node by original number or create new one.
     * @param int $originalNumber
     * @param array $data
     * @return NestableModel
     * @throws Exception
     */
    public function firstOrCreateRoot(int $originalNumber, array $data): NestableModel
    {
        $root = $this->model->newQueryWithoutScopes()
            ->whereOriginalNumber($originalNumber)
            ->whereRoot()
            ->first();
        if ($root) {
            return $root;
        }

        return $this->createRootNode($originalNumber, $data);
    }

    /**
     * Find root node by original number or create new one by backup object.
     * @param int $originalNumber
     * @return NestableModel
     * @throws Exception
     */
    public function firstOrCreateBackupRoot(int $originalNumber): NestableModel
    {
        $root = $this->model->newQueryWithoutScopes()
            ->whereOriginalNumber($originalNumber)
            ->whereRoot()
            ->first();
        if ($root) {
            return $root;
        }

        $root = $this->model->newBackupRootObject($originalNumber);
        $root->saveQuietly();

        return $root;
    }

    /**
     * Delete nodes.
     */
    public function deleteNodes(): bool
    {
        /* @var NestableModel $model */
        $model = $this->model;

        /* @var NestableModel[]|Collection $parents Main nodes */
        $parents = $this->get()->sortBy($model->getLeftKey());

        /* @var ElonestBuilder $childQuery */
        $childQuery = $model->newInstance()->newQueryWithoutScopes();
        $childQuery->where(function ($query) use ($parents) {
            /* @var NestableModel $parent */
            foreach ($parents as $parent) {
                $query->orWhere(function (ElonestBuilder $query) use ($parent) {
                    $query
                        ->whereChildren($parent->getLeftValue(), $parent->getRightValue())
                        ->whereOriginalNumber($parent->getOriginalNumberValue());
                });
            }
        });

        DB::beginTransaction();

        // Delete all child.
        $childQuery->delete();

        $subtractionAmount = 0;
        foreach ($parents as $parent) {
            // Subtract amount of previous parent
            // (due to transaction has not committed so left and right current parent has not changed).
            $parent->setLeftValue($parent->getLeftValue() - $subtractionAmount);
            $parent->setRightValue($parent->getRightValue() - $subtractionAmount);

            // Amount to subtract current parent.
            $currentSubtractionAmount = $parent->getRightValue() - $parent->getLeftValue() + 1;

            // Amount to subtract next parent.
            $subtractionAmount += $currentSubtractionAmount;

            // Decreasing left of other right nodes.
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($model->getLeftKey(), '>', $parent->getRightValue())
                ->decrement($model->getLeftKey(), $currentSubtractionAmount);

            // Decreasing right of other right nodes.
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($model->getRightKey(), '>', $parent->getRightValue())
                ->decrement($model->getRightKey(), $currentSubtractionAmount);

            // Delete parent.
            $parent->deleteQuietly();
        }

        DB::commit();

        return true;
    }

    /**
     * Move node.
     *
     * @param int $prev Previous value
     * @param int $next Next value
     *
     * @return bool
     *
     * @throws Exception
     */
    public function moveNode(int $prev, int $next): bool
    {
        /* @var NestableModel $model */
        $model = $this->model;

        /* @var NestableModel[]|Collection $nodes Main nodes */
        $nodes = $this->get()->sortBy($model->getLeftKey());
        if (!$nodes->count()) {
            throw new Exception('Does not have any nodes were found to moving');
        }

        DB::beginTransaction();

        /* @var NestableModel $model */
        foreach ($nodes as $node) {
            if (!$this->isValidPrevNext($node, $prev, $next)) {
                throw new Exception('Previous and Next values are invalid');
            }

            if ($node->getLeftValue() < $prev) {
                // Update node to right side (drag node to right).
                $this->moveNodeToRight($node, $prev, $next);
            } elseif ($node->getLeftValue() > $prev) {
                // Update node to left side (drag node to left).
                $this->moveNodeToLeft($node, $prev, $next);
            }
        }

        DB::commit();

        return true;
    }

    /**
     * Update node to right side.
     *
     * @param NestableModel $node Node will be updated
     * @param int $prev Left value is in previous side of node
     * @param int $next Right value is in nest side of node
     *
     * @return NestableModel
     *
     * @throws Exception
     */
    protected function moveNodeToRight(NestableModel $node, int $prev, int $next): NestableModel
    {
        try {
            DB::beginTransaction();

            $model = $this->model;

            /** Update parent and get new depth of updating node. */
            $targetNode = $this->getTargetNodeOf($node, $prev);
            $this->setParentWhenMoving($prev, $targetNode, $node);
            $node->save();
            $depthOperation = $this->getDepthOperationWhenMoving($prev, $targetNode, $node);

            /** Step 0: Get updating node and children IDs before another nodes are modified */
            $nodeAndChildrenIds = $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNodeAndChildren($node->getLeftValue(), $node->getRightValue())
                ->get()
                ->pluck($model->getPrimaryName());

            /** Step 1: Calculate spaceOne. */
            $spaceOne = ($node->getRightValue() - $node->getLeftValue()) + 1;

            /** Step 2: Update RIGHT another nodes have:
             * RIGHT > updating node RIGHT
             * and RIGHT <= willBeSibling RIGHT
             * and Excluding updating node, and it's children
             */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNotIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->where($model->getRightKey(), '>', $node->getRightValue())
                ->where($model->getRightKey(), '<=', $prev)
                ->update([
                    $model->getRightKey() => DB::raw($model->getRightKey() . " - $spaceOne"),
                ]);

            /** Step 3: Update LEFT another nodes have:
             * LEFT > updating node RIGHT
             * and LEFT <= willBeSibling RIGHT
             * and Excluding updating node, and it's children
             */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNotIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->where($model->getLeftKey(), '>', $node->getRightValue())
                ->where($model->getLeftKey(), '<=', $prev)
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " - $spaceOne"),
                ]);

            /** Step 4: Calculate spaceTwo. */
            $spaceTwo = ($prev - ($node->getLeftValue() + $spaceOne)) + 1;

            /** Step 5: Update LEFT and RIGHT of both updating node, and it's children */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " + $spaceTwo"),
                    $model->getRightKey() => DB::raw($model->getRightKey() . " + $spaceTwo"),
                    $model->getDepthKey() => DB::raw($model->getDepthKey() . $depthOperation[0] . $depthOperation[1]),
                ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        return $node->refresh();
    }

    /**
     * Update node to left side.
     *
     * @param NestableModel $node Node will be updated
     * @param int $prev Left value is in previous side of node
     * @param int $next Right value is in nest side of node
     *
     * @return NestableModel
     *
     * @throws Exception
     */
    protected function moveNodeToLeft(NestableModel $node, int $prev, int $next): NestableModel
    {
        try {
            DB::beginTransaction();

            $model = $this->model;

            /** Update parent and get new depth of updating node. */
            $targetNode = $this->getTargetNodeOf($node, $prev);
            $this->setParentWhenMoving($prev, $targetNode, $node);
            $node->save();
            $depthOperation = $this->getDepthOperationWhenMoving($prev, $targetNode, $node);

            /** Step 0: Get updating node and children IDs before another nodes are modified */
            $nodeAndChildrenIds = $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNodeAndChildren($node->getLeftValue(), $node->getRightValue())
                ->get()
                ->pluck($model->getPrimaryName());

            /** Step 1: Calculate spaceOne and spaceTwo. */
            $spaceOne = ($node->getRightValue() - $node->getLeftValue()) + 1;
            $spaceTwo = $node->getLeftValue() - $next;

            /** Step 2: Update LEFT another nodes have:
             * LEFT >= next value
             * and LEFT < updating node LEFT
             * and Excluding updating node, and it's children
             */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNotIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->where($model->getLeftKey(), '>=', $next)
                ->where($model->getLeftKey(), '<', $node->getLeftValue())
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " + $spaceOne"),
                ]);

            /** Step 3: Update RIGHT another nodes have:
             * RIGHT >= next value
             * and RIGHT < updating node LEFT
             * and Excluding updating node, and it's children
             */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNotIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->where($model->getRightKey(), '>=', $next)
                ->where($model->getRightKey(), '<', $node->getLeftValue())
                ->update([
                    $model->getRightKey() => DB::raw($model->getRightKey() . " + $spaceOne"),
                ]);

            /** Step 4: Update LEFT and RIGHT of both updating node, and it's children */
            $model->newInstance()->newQueryWithoutScopes()
                ->whereIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " - $spaceTwo"),
                    $model->getRightKey() => DB::raw($model->getRightKey() . " - $spaceTwo"),
                    $model->getDepthKey() => DB::raw($model->getDepthKey() . $depthOperation[0] . $depthOperation[1]),
                ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        return $node->refresh();
    }

    /**
     * Get target node which specify node moves to.
     *
     * @param NestableModel $node Node will move to target
     * @param int $prev Previous value to get target node
     * @return NestableModel
     * @throws Exception
     */
    protected function getTargetNodeOf(NestableModel $node, int $prev): NestableModel
    {
        $model = $this->model;

        /* @var NestableModel $targetNode */
        $targetNode = $model->newInstance()->newQueryWithoutScopes()
            ->whereOriginalNumber($node->getOriginalNumberValue())
            ->where(function ($query) use ($prev, $model) {
                $query->where($model->getRightKey(), '=', $prev)
                    ->orWhere($model->getLeftKey(), '=', $prev);
            })
            ->first();

        // TODO: review and handle this case.
        if (!$targetNode) {
            throw new Exception("Node has RIGHT = $prev or LEFT = $prev is not found");
        }

        return $targetNode;
    }

    /**
     * Determines target node will be a sibling one.
     *
     * @param NestableModel $targetNode
     * @param int $prev
     * @return bool
     */
    protected function isSiblingTarget(NestableModel $targetNode, int $prev): bool
    {
        return $targetNode->getRightValue() == $prev;
    }

    /**
     * Determines target node will be a parent one.
     *
     * @param NestableModel $targetNode
     * @param int $prev
     * @return bool
     */
    protected function isParentTarget(NestableModel $targetNode, int $prev): bool
    {
        return $targetNode->getLeftValue() == $prev;
    }

    /**
     * Set parent ID for node after it will be moved.
     *
     * @param int $prev Previous value to determine relation
     * @param NestableModel $node Node need to set parent
     *
     * @throws Exception
     */
    protected function setParentWhenMoving(int $prev, NestableModel $targetNode, NestableModel $node): void
    {
        if ($this->isParentTarget($targetNode, $prev)) {
            $node->setParentId($targetNode->getPrimaryId());
        } elseif ($this->isSiblingTarget($targetNode, $prev)) {
            $node->setParentId($targetNode->getParentId());
        }
    }

    /**
     * Get operation for depth node after it will be moved.
     *
     * @param NestableModel $targetNode
     * @param NestableModel $node
     * @return array
     */
    protected function getDepthOperationWhenMoving(int $prev, NestableModel $targetNode, NestableModel $node): array
    {
        $oldDepth = $node->getDepthValue();
        $newDepth = $oldDepth;

        if ($this->isParentTarget($targetNode, $prev)) {
            $newDepth = $targetNode->getDepthValue() + 1;
        } elseif ($this->isSiblingTarget($targetNode, $prev)) {
            $newDepth = $targetNode->getDepthValue();
        }

        switch (true) {
            case $oldDepth > $newDepth:
                $operation = ['-', $oldDepth - $newDepth]; break;
            case $oldDepth < $newDepth:
                $operation = ['+', $newDepth - $oldDepth]; break;
            default:
                $operation = ['+', 0];
        }

        return $operation;
    }

    /**
     * Check previous and next values are correct.
     *
     * @param NestableModel $node Node will be updated
     * @param int $prev Left value is in previous side of node
     * @param int $next Right value is in nest side of node
     *
     * @return bool
     */
    protected function isValidPrevNext(NestableModel $node, int $prev, int $next): bool
    {
        // Prev is not greater than equal Next.
        if ($prev >= $next) {
            return false;
        }

        // Prev are not in node's range.
        if ($prev >= $node->getLeftValue() && $prev <= $node->getRightValue()) {
            return false;
        }

        // Next are not in node's range.
        if ($next >= $node->getLeftValue() && $next <= $node->getRightValue()) {
            return false;
        }

        return true;
    }
}
