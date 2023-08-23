<?php

namespace Minh164\EloNest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\Listeners\HandleNodeCreating;
use Minh164\EloNest\Listeners\HandleNodeDeleting;
use Minh164\EloNest\Relations\NestedChildrenRelation;
use Minh164\EloNest\Relations\NextSiblingRelation;
use Minh164\EloNest\Relations\NodeRelation;
use Minh164\EloNest\Relations\ParentsRelation;
use Minh164\EloNest\Relations\PreviousSiblingRelation;
use Minh164\EloNest\Traits\NestableVariablesTrait;
use Exception;

/**
 * Layer manipulate nested set model.
 *
 * @property-read NestableModel[]|ElonestCollection $children
 * @property-read NestableModel $prevSibling Previous sibling
 * @property-read NestableModel $nextSibling Next sibling
 */
abstract class NestableModel extends Model
{
    use NestableVariablesTrait;

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @inheritdoc
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newEloquentBuilder($query): Builder
    {
        return new ElonestBuilder($query);
    }

    /**
     * @return ElonestBuilder
     */
    public static function query(): ElonestBuilder
    {
        return parent::query();
    }

    /**
     * @return ElonestBuilder
     */
    public function newQuery(): ElonestBuilder
    {
        return parent::newQuery();
    }

    /**
     * @return ElonestBuilder|Model
     */
    public function newQueryWithoutScopes(): ElonestBuilder|Model
    {
        return parent::newQueryWithoutScopes();
    }

    /**
     * Get nested set model collection.
     *
     * @inheritDoc
     * @param array $models
     *
     * @return ElonestCollection
     */
    public function newCollection(array $models = []): ElonestCollection
    {
        return new ElonestCollection($models);
    }

    /**
     * Create a new Node Relation query builder for the model.
     *
     * @param NodeRelation|null $nodeRelation
     * @return NodeRelationBuilder
     */
    public function newNodeRelationBuilder(?NodeRelation $nodeRelation = null): NodeRelationBuilder
    {
        $builder = new NodeRelationBuilder($this->newBaseQueryBuilder(), $nodeRelation);
        $builder->setModel($this);

        return $builder;
    }

    /**
     * Root model object to use for repair model set if it misses root model.
     * Just provide a new Model without save to database.
     *
     * @param int $originalNumber
     * @return $this
     */
    abstract public function newBackupRootObject(int $originalNumber): static;

    /**
     * @inheritdoc
     *
     * @param string $key
     * @return mixed|null
     * @throws Exception
     */
    public function __get($key)
    {
        // Check to return node relation.
        if (method_exists(static::class, $key) && $this->$key() instanceof NodeRelationBuilder) {
            // If node relation has been loaded, it will be returned instead of re-query to get again.
            if ($this->nodeRelationLoaded($key)) {
                return $this->nodeRelations[$key];
            }

            /* @var NodeRelationBuilder $query */
            $query = $this->$key();
            if (! $nodeRelation = $query->getNodeRelationInstance()) {
                return null;
            }

            return $nodeRelation->hasMany() ? $query->get() : $query->first();
        }

        return parent::__get($key);
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(HandleNodeCreating::class);
        static::deleting(HandleNodeDeleting::class);
    }

    /**
     * Determines relation has been loaded.
     *
     * @param string $key Relation key to check
     *
     * @return bool
     */
    public function nodeRelationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->nodeRelations);
    }

    /**
     * Load node relations.
     *
     * @param string|array $relationKeys Relation keys
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function loadNodeRelations(mixed $relationKeys): static
    {
        $newQuery = $this->newQueryWithoutScopes()->withNodes($relationKeys);

        $newQuery->eagerLoadNodeRelations(new ElonestCollection([$this]));

        return $this;
    }

    /**
     * @inheritDoc
     *
     * Reload the current model instance with fresh attributes from the database and reload node relations.
     *
     * @return $this
     * @throws \Exception
     */
    public function refresh(): static
    {
        parent::refresh();

        // Reload node relations.
        foreach ($this->nodeRelations as $relationKey => $value) {
            if (!$this->nodeRelationLoaded($relationKey)) {
                continue;
            }

            $this->loadNodeRelations($relationKey);
        }

        return $this;
    }

    /**
     * Convert the model instance to an array.
     *
     * @inheritdoc
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $nodesData = [];

        if (count($this->nodeRelations)) {
            foreach ($this->nodeRelations as $key => $nodeRelation) {
                if (static::$snakeAttributes) {
                    $key = Str::snake($key);
                }

                $nodesData[$key] = null;

                if ($nodeRelation instanceof ElonestCollection) {
                    // Recursive processing for each item in collection.
                    $nodesData[$key] = array_values($nodeRelation->toArray());
                } elseif ($nodeRelation instanceof NestableModel) {
                    // Recursive processing for one item.
                    $nodesData[$key] = $nodeRelation->toArray();
                }

            }
        }

        return array_merge($data, $nodesData);
    }

    /**
     * Get all children query.
     *
     * @param int|null $depth Null will get children from all depths
     * @return NodeRelationBuilder
     * @throws ElonestException
     * @throws Exception
     */
    public function children(?int $depth = null): NodeRelationBuilder
    {
        return (new NestedChildrenRelation($this, $depth))->getQuery();
    }

    /**
     * Get parents query.
     *
     * @return NodeRelationBuilder
     */
    public function parents(): NodeRelationBuilder
    {
        return (new ParentsRelation($this))->getQuery();
    }

    /**
     * Get previous sibling query.
     *
     * @return NodeRelationBuilder
     */
    public function prevSibling(): NodeRelationBuilder
    {
        return (new PreviousSiblingRelation($this))->getQuery();
    }

    /**
     * Get next sibling query.
     *
     * @return NodeRelationBuilder
     */
    public function nextSibling(): NodeRelationBuilder
    {
        return (new NextSiblingRelation($this))->getQuery();
    }

    /**
     * Get the latest original number.
     *
     * @return int
     * @throws Exception
     */
    public function getMaxOriginalNumber(): int
    {
        /* @var NestableModel $node */
        $node = (new static())->newQueryWithoutScopes()
            ->select($this->getOriginalNumberKey())
            ->groupBy($this->getOriginalNumberKey())
            ->orderBy($this->getOriginalNumberKey(), 'DESC')
            ->first();

        return $node?->getOriginalNumberValue() ?? 0;
    }

    /**
     * Count total depths in node.
     *
     * @return int
     * @throws Exception
     */
    public function countDepths(): int
    {
        $lowestChild = $this->newQueryWithoutScopes()
            ->whereChildren($this->getLeftValue(), $this->getRightValue())
            ->whereOriginalNumber($this->getOriginalNumberValue())
            ->orderBy($this->getDepthKey(), 'DESC')
            ->first();

        if (!$lowestChild) {
            return 0;
        }

        return $lowestChild->getDepthValue() - $this->getDepthValue();
    }

    /**
     * Determines this is the sibling of target node.
     *
     * @param NestableModel $targetNode Node which be need to compare
     *
     * @return bool
     */
    public function isSiblingOf(NestableModel $targetNode): bool
    {
        return $this->getParentId() == $targetNode->getParentId();
    }

    /**
     * Determines this is the child of target node.
     *
     * @param NestableModel $targetNode
     *
     * @return bool
     */
    public function isChildOf(NestableModel $targetNode): bool
    {
        return $this->getLeftValue() > $targetNode->getLeftValue() && $this->getRightValue() < $targetNode->getRightValue();
    }

    /**
     * Determines this is the parent of target node.
     *
     * @param NestableModel $targetNode
     *
     * @return bool
     */
    public function isParentOf(NestableModel $targetNode): bool
    {
        return $this->getLeftValue() < $targetNode->getLeftValue() && $this->getRightValue() > $targetNode->getRightValue();
    }

    /**
     * Determines this is the closest parent of target node.
     *
     * @param NestableModel $targetNode
     * @return bool
     */
    public function isClosestParentOf(NestableModel $targetNode): bool
    {
        return $this->getPrimaryId() == $targetNode->getParentId();
    }

    /**
     * Determines this is the closest child of target node.
     *
     * @param NestableModel $targetNode
     * @return bool
     */
    public function isClosestChildOf(NestableModel $targetNode): bool
    {
        return $this->getParentId() == $targetNode->getPrimaryId();
    }

    /**
     * Determines is root model.
     *
     * @return bool
     */
    public function isRoot(): bool
    {
        return $this->getParentId() == $this->getRootNumber();
    }

    /**
     * Determines has root in current model's set.
     *
     * @return bool
     */
    public function hasRoot(): bool
    {
        return $this->newQueryWithoutScopes()
            ->whereOriginalNumber($this->getOriginalNumberValue())
            ->whereRoot()
            ->exists();
    }

    /**
     * Move node to specified position.
     *
     * @param int $prev
     * @param int $next
     * @return void
     * @throws Exception
     */
    public function moveTo(int $prev, int $next): void
    {
        $this->newQueryWithoutScopes()->where($this->getPrimaryName(), $this->getPrimaryId())->moveNode($prev, $next);
    }

    /**
     * Move node after a target node (move to right).
     *
     * @param NestableModel $targetNode
     * @return void
     * @throws Exception
     */
    public function moveAfter(NestableModel $targetNode): void
    {
        $this->newQueryWithoutScopes()
            ->where($this->getPrimaryName(), $this->getPrimaryId())
            ->moveNode($targetNode->getRightValue(), $targetNode->getRightValue() + 1);
    }

    /**
     * Move node before a target node (move to left).
     *
     * @param NestableModel $targetNode
     * @return void
     * @throws Exception
     */
    public function moveBefore(NestableModel $targetNode): void
    {
        $this->newQueryWithoutScopes()
            ->where($this->getPrimaryName(), $this->getPrimaryId())
            ->moveNode($targetNode->getLeftValue() - 1, $targetNode->getLeftValue());
    }

    /**
     * Move node into parent (moved node will be the last in parent).
     *
     * @param NestableModel $targetNode
     * @return void
     * @throws Exception
     */
    public function moveIn(NestableModel $targetNode): void
    {
        $this->newQueryWithoutScopes()
            ->where($this->getPrimaryName(), $this->getPrimaryId())
            ->moveNode($targetNode->getRightValue() - 1, $targetNode->getRightValue());
    }
}
