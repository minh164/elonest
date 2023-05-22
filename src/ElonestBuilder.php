<?php

namespace Minh164\EloNest;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\Collections\ElonestCollection;
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
    public function get($columns = ['*']): ElonestCollection
    {
        /** @var ElonestCollection $mainNodes */
        $mainNodes = parent::get($columns);

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
    public function first($columns = ['*']): ?NestableModel
    {
        $node = parent::first($columns);

        if ($node) {
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
                // Check relation is existed in model.
                if (!method_exists($this->model::class, $relation)) {
                    throw new Exception("$relation relation is not existed");
                }

                // Check relation is NodeRelation instance.
                if (!$this->model->$relation() instanceof NodeRelation) {
                    throw new Exception("$relation is not a " . NodeRelation::class . " instance");
                }

                $relationNodes = $this->getRelationChild($mainNodes, $relation);

                /* @var NodeRelation $nodeRelation */
                $nodeRelation = $this->model->$relation();

                $mainNodes = $this->setChildrenToParents(
                    $mainNodes,
                    $relationNodes,
                    $nodeRelation,
                    $relation
                );
            }
        }

        return $mainNodes;
    }

    /**
     * Get all relation child nodes of parent nodes.
     *
     * @param Collection $parentNodes Parent node collection
     * @param string $relation Relation name in model
     *
     * @return Collection
     */
    protected function getRelationChild(Collection $parentNodes, string $relation): Collection
    {
        $childQuery = $this->model->newQuery();

        $childQuery->where(function ($query) use ($parentNodes, $relation) {
            /* @var NestableModel $parentNode */
            foreach ($parentNodes as $parentNode) {
                /* @var NodeRelation $nodeRelation */
                $nodeRelation = $parentNode->$relation();

                $query->orWhere(function ($query) use ($nodeRelation, $parentNode) {
                    $nodeRelation
                        ->getQuery($query)
                        ->whereOriginalNumber($parentNode->getOriginalNumberValue());
                });
            }
        });

        return $childQuery->get();
    }

    /**
     * Arrange relation nodes into main nodes following level.
     *
     * @param Collection $parentNodes Parent nodes collection which will be returned at root list
     * @param Collection $allChildNodes All child nodes of parent nodes collection
     * @param NodeRelation $nodeRelation Node relation instance
     * @param string $relationKey Key to set relation
     *
     * @return Collection
     *
     * @throws Exception
     */
    protected function setChildrenToParents(
        Collection $parentNodes,
        Collection $allChildNodes,
        NodeRelation $nodeRelation,
        string $relationKey
    ): Collection {
        $relations = [];

        $mapping = $nodeRelation->getMapping();

        /* @var NestableModel $node */
        foreach ($parentNodes as $node) {
            $keyToMap = $mapping->getKeyToMap();
            $operation = $mapping->getOperation();

            // Processing valueToMap.
            $valueKey = $mapping->getValueToMap()[0] ?? null;
            $valueOperation = $mapping->getValueToMap()[1] ?? null;
            $valueNumber = $mapping->getValueToMap()[2] ?? null;

            $valueToMap = $node->$valueKey;
            if ($valueKey && $valueOperation && $valueNumber) {
                $valueToMap = match ($valueOperation) {
                    '+' => $node->$valueKey + $valueNumber,
                    '-' => $node->$valueKey - $valueNumber,
                    '*' => $node->$valueKey * $valueNumber,
                    '/' => $node->$valueKey / $valueNumber,
                };
            }

            $childNodes = $allChildNodes
                ->where($keyToMap, $operation, $valueToMap)
                ->sortBy($node->getLeftKey());

            // Recursive processing if isNested turn ON.
            if ($childNodes->count() && $nodeRelation->isNested) {
                $childNodes = $this->setChildrenToParents($childNodes, $allChildNodes, $nodeRelation, $relationKey);
            }

            $relations[$relationKey] = $nodeRelation->hasMany() ? $childNodes : $childNodes->first();
            $node->setNodeRelations($relations);
        }

        return $parentNodes;
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
     * @param array $data Attribute data
     * @param int|null $parentId ID of parent
     *
     * @return NestableModel
     */
    public function createNode(array $data, int $parentId = null): NestableModel
    {
        if (!$parentId) {
            return $this->createRootNode($data);
        }

        $insert = $data;

        DB::beginTransaction();

        /* @var NestableModel $parent */
        $parent = $this->model->newInstance()::findOrFail($parentId);
        if ($parent) {
            $insert[$parent->getLeftKey()] = $parent->getRightValue();
            $insert[$parent->getRightKey()] = $insert[$parent->getLeftKey()] + 1;
            $insert[$parent->getParentKey()] = $parent->getPrimaryId();
            $insert[$parent->getDepthKey()] = $parent->getDepthValue() + 1;
            $insert[$parent->getOriginalNumberKey()] = $parent->getOriginalNumberValue();

            // Update right value of another nodes.
            $this->model
                ->newInstance()
                ->newQuery()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($parent->getRightKey(), '>=', $parent->getRightValue())
                ->increment($parent->getRightKey(), 2);

            // Update left value of another nodes.
            $this->model
                ->newInstance()
                ->newQuery()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($parent->getLeftKey(), '>', $parent->getRightValue())
                ->increment($parent->getLeftKey(), 2);
        }

        /* @var NestableModel $node*/
        $node = parent::create($insert);

        DB::commit();

        return $node;
    }

    /**
     * @param array $data
     * @return NestableModel
     */
    protected function createRootNode(array $data): NestableModel
    {
        $insert = $data;

        /* @var NestableModel $model */
        $model = $this->model;

        $insert[$model->getLeftKey()] = 1;
        $insert[$model->getRightKey()] = 2;
        $insert[$model->getOriginalNumberKey()] = $model->getMaxOriginalNumber() + 1;

        /* @var static $builder */
        $builder = $model->newInstance()->newQuery();

        /* @var NestableModel $latestRoot */
        $latestRoot = $builder
            ->where($model->getParentKey(), 0)
            ->orderBy($model->getRightKey(), 'DESC')
            ->first();

        if ($latestRoot) {
            $insert[$model->getLeftKey()] = $latestRoot->getRightValue() + 1;
            $insert[$model->getRightKey()] = $latestRoot->getRightValue() + 2;
        }

        return parent::create($insert);
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

        // Child query.
        $childQuery = $model->newInstance()->newQuery();
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
            $model->newInstance()->newQuery()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($model->getLeftKey(), '>', $parent->getRightValue())
                ->decrement($model->getLeftKey(), $currentSubtractionAmount);

            // Decreasing right of other right nodes.
            $model->newInstance()->newQuery()
                ->whereOriginalNumber($parent->getOriginalNumberValue())
                ->where($model->getRightKey(), '>', $parent->getRightValue())
                ->decrement($model->getRightKey(), $currentSubtractionAmount);

            // Delete parent.
            $parent->delete();
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

            /* @var NestableModel $model */
            $model = $this->model;

            /** Step 6: Update parent of updating node. */
            $this->setParentAfterMoving($prev, $node);
            $node->save();

            /** Step 0: Get updating node and children IDs before another nodes are modified */
            $nodeAndChildrenIds = $model->newInstance()->newQuery()
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
            $model->newInstance()->newQuery()
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
            $model->newInstance()->newQuery()
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
            $model->newInstance()->newQuery()
                ->whereIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " + $spaceTwo"),
                    $model->getRightKey() => DB::raw($model->getRightKey() . " + $spaceTwo"),
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

            /* @var NestableModel $model */
            $model = $this->model;

            /** Step 5: Update parent of updating node. */
            $this->setParentAfterMoving($prev, $node);
            $node->save();

            /** Step 0: Get updating node and children IDs before another nodes are modified */
            $nodeAndChildrenIds = $model->newInstance()->newQuery()
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
            $model->newInstance()->newQuery()
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
            $model->newInstance()->newQuery()
                ->whereOriginalNumber($node->getOriginalNumberValue())
                ->whereNotIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->where($model->getRightKey(), '>=', $next)
                ->where($model->getRightKey(), '<', $node->getLeftValue())
                ->update([
                    $model->getRightKey() => DB::raw($model->getRightKey() . " + $spaceOne"),
                ]);

            /** Step 4: Update LEFT and RIGHT of both updating node, and it's children */
            $model->newInstance()->newQuery()
                ->whereIn($model->getPrimaryName(), $nodeAndChildrenIds)
                ->update([
                    $model->getLeftKey() => DB::raw($model->getLeftKey() . " - $spaceTwo"),
                    $model->getRightKey() => DB::raw($model->getRightKey() . " - $spaceTwo"),
                ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        return $node->refresh();
    }

    /**
     * Set parent ID for node after it was moved.
     *
     * @param int $prev Previous value to get relation node
     * @param NestableModel $node Node need to set parent
     *
     * @throws Exception
     */
    protected function setParentAfterMoving(int $prev, NestableModel $node): void
    {
        $model = $this->model;

        /* @var NestableModel $relationNode */
        $relationNode = $model->newInstance()->newQuery()
            ->whereOriginalNumber($node->getOriginalNumberValue())
            ->where(function ($query) use ($prev, $model) {
                $query->where($model->getRightKey(), '=', $prev)
                    ->orWhere($model->getLeftKey(), '=', $prev);
            })
            ->first();

        // TODO: review and handle this case.
        if (!$relationNode) {
            throw new Exception("Node has RIGHT = $prev or LEFT = $prev is not found");
        }

        if ($relationNode->getLeftValue() == $prev) {
            // If relation node is parent of node.
            $node->setParentId($relationNode->getPrimaryId());
        } elseif ($relationNode->getRightValue() == $prev) {
            // If relation node is sibling of node.
            $node->setParentId($relationNode->getParentId());
        }
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