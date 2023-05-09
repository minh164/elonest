<?php

namespace Minh164\EloNest;

use Minh164\EloNest\Relations\NestedChildrenRelation;
use Minh164\EloNest\Relations\NextSiblingRelation;
use Minh164\EloNest\Relations\NodeRelation;
use Minh164\EloNest\Relations\PreviousSiblingRelation;
use Minh164\EloNest\Traits\NestableVariablesTrait;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Layer manipulate nested set model.
 *
 * @property-read NestableModel[]|NestedCollection $nodeChildren
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
        return new NodeBuilder($query);
    }

    /**
     * @return NodeBuilder
     */
    public static function query(): NodeBuilder
    {
        return parent::query();
    }

    /**
     * @return NodeBuilder
     */
    public function newQuery(): NodeBuilder
    {
        return parent::newQuery();
    }

    /**
     * Get nested collection.
     *
     * @param array $models
     *
     * @return NestedCollection
     */
    public function newCollection(array $models = []): NestedCollection
    {
        return new NestedCollection($models);
    }

    /**
     * @inheritdoc
     *
     * @param string $key
     * @return mixed|null
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

        $newQuery->eagerLoadNodeRelations(new NestedCollection([$this]));

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

                if ($nodeRelation instanceof NestedCollection) {
                    $nodesData[$key] = array_values($nodeRelation->toArray());
                } elseif ($nodeRelation instanceof NestableModel) {
                    $nodesData[$key] = $nodeRelation->toArray();
                }

            }
        }

        return array_merge($data, $nodesData);
    }

    /**
     * Get all children query.
     *
     * @return NestedChildrenRelation
     */
    public function nodeChildren(): NestedChildrenRelation
    {
        return new NestedChildrenRelation($this);
    }

    /**
     * Get previous sibling query.
     *
     * @return PreviousSiblingRelation
     */
    public function prevSibling(): PreviousSiblingRelation
    {
        return new PreviousSiblingRelation($this);
    }

    /**
     * Get next sibling query.
     *
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
     * Determines has the same parent with another node.
     *
     * @param NestableModel $anotherNode Node which be need to compare
     *
     * @return bool
     */
    public function isTheSameParent(NestableModel $anotherNode): bool
    {
        return $this->getParentId() == $anotherNode->getParentId();
    }
}
