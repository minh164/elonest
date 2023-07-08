<?php

namespace Minh164\EloNest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\Listeners\HandleNodeCreating;
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
 * @property-read NestableModel[]|ElonestCollection $nodeChildren
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
        if (method_exists(static::class, $key) && $this->$key() instanceof NodeRelation) {
            // If node relation has been loaded, it will be returned instead of re-query to get again.
            if ($this->nodeRelationLoaded($key)) {
                return $this->nodeRelations[$key];
            }

            /* @var NodeRelation $query */
            $query = $this->$key();

            return $query->execute();
        }

        return parent::__get($key);
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(HandleNodeCreating::class);
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
        $newQuery = $this->newQuery()->withNodes($relationKeys);

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
     * @param int|null $depth
     * @return NestedChildrenRelation
     */
    public function children(?int $depth = null): NestedChildrenRelation
    {
        return new NestedChildrenRelation($this, $depth);
    }

    /**
     * Get parents query.
     * @return ParentsRelation
     */
    public function parents(): ParentsRelation
    {
        return new ParentsRelation($this);
    }

    /**
     * Get previous sibling query.
     * @return PreviousSiblingRelation
     */
    public function prevSibling(): PreviousSiblingRelation
    {
        return new PreviousSiblingRelation($this);
    }

    /**
     * Get next sibling query.
     * @return NextSiblingRelation
     */
    public function nextSibling(): NextSiblingRelation
    {
        return new NextSiblingRelation($this);
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
        $node = (new static())->newQuery()
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
        $lowestChild = $this->newQuery()
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
     * Determines another node is sibling of this.
     *
     * @param NestableModel $anotherNode Node which be need to compare
     *
     * @return bool
     */
    public function isSiblingOf(NestableModel $anotherNode): bool
    {
        return $this->getParentId() == $anotherNode->getParentId();
    }

    /**
     * Determines this is the closest parent of another node.
     *
     * @param NestableModel $anotherNode
     * @return bool
     */
    public function isClosestParentOf(NestableModel $anotherNode): bool
    {
        return $this->getPrimaryId() == $anotherNode->getParentId();
    }

    /**
     * Determines this is the closest child of another node.
     *
     * @param NestableModel $anotherNode
     * @return bool
     */
    public function isClosestChildOf(NestableModel $anotherNode): bool
    {
        return $this->getParentId() == $anotherNode->getPrimaryId();
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
        return $this->newQuery()
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
        $this->newQuery()->where($this->getPrimaryName(), $this->getPrimaryId())->moveNode($prev, $next);
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
        $this->newQuery()
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
        $this->newQuery()
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
        $this->newQuery()
            ->where($this->getPrimaryName(), $this->getPrimaryId())
            ->moveNode($targetNode->getRightValue() - 1, $targetNode->getRightValue());
    }
}
