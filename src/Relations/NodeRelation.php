<?php

namespace Minh164\EloNest\Relations;

use Illuminate\Database\Eloquent\Model;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\NestableModel;
use Minh164\EloNest\ElonestBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Exception;

/**
 * @mixin ElonestBuilder
 */
abstract class NodeRelation
{
    /**
     * Node model.
     */
    protected NestableModel $model;

    /**
     * Determines process will be recursive to get relation.
     */
    public bool $isNested = false;

    /**
     * Determines Has one or Has many records in relation.
     */
    protected bool $hasMany = false;

//    /**
//     * Relation query.
//     */
//    protected Builder $query;

    public function __construct(NestableModel $model)
    {
        $this->model = $model;
//        $this->query = $this->getQuery();
    }

    /**
     * When calling to non-existing method, then __call will be invoked and get method from Node Builder.
     *
     * @param string $name Method name
     * @param array $arguments Params of method
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
//        return $this->query->$name(...$arguments);
    }

    /**
     * Return conditions array to execute get relation and map main node with its relation nodes.
     * Example:
     * [
     *      ["column_1", "operation_1", "value_1"],
     *      ["column_2", "operation_2", "value_2"],
     * ]
     *
     * @return array
     */
    abstract protected function relatedConditions(): array;

    /**
     * @return bool
     */
    public function hasMany(): bool
    {
        return $this->hasMany;
    }

    /**
     * Return relations query.
     *
     * @param ElonestBuilder $query
     * @return Builder
     * @throws ElonestException
     */
    public function getQuery(ElonestBuilder $query): Builder
    {
        if (empty($query) || !count($this->relatedConditions()) || empty($this->model->getOriginalNumberValue())) {
            throw new Exception("relatedConditions() is null or Original Number is missing");
        }

        // TODO: process with OR condition later.
        return $query
            ->where($this->relatedConditions())
            ->whereOriginalNumber($this->model->getOriginalNumberValue());
    }

    /**
     * Return result query.
     *
     * @return null|Model|Collection
     * @throws Exception
     */
    public function execute(): null|Model|Collection
    {
        // Only catch error from getQuery().
        try {
            $builder = $this->getQuery($this->model->newQuery());
        } catch (ElonestException $e) {
            return null;
        }

        if ($this->hasMany()) {
            return $builder->get();
        }
        return $builder->first();
    }

    /**
     * Arrange relation nodes into main nodes following depths.
     *
     * @param ElonestCollection $mainNodes Main nodes collection which will be returned at root list
     * @param ElonestCollection $allRelatedNodes All related nodes of main nodes collection
     * @param string $relationKey Key to set relation
     *
     * @return ElonestCollection
     *
     * @throws Exception
     */
    public function mapRelationsToMains(
        ElonestCollection $mainNodes,
        ElonestCollection $allRelatedNodes,
        string $relationKey
    ): Collection {
        $relations = [];

        /* @var NestableModel $node */
        foreach ($mainNodes as $node) {
            $this->model = $node;
            $relatedNodes = $this->filterRelationsForMain($node, $allRelatedNodes);

            // Recursive processing if isNested turn ON.
            if ($relatedNodes->count() && $this->isNested) {
                $relatedNodes = $this->mapRelationsToMains($relatedNodes, $allRelatedNodes, $relationKey);
            }

            if ($relatedNodes->count() <= 0) {
                $relations[$relationKey] = null;
            } else {
                $relations[$relationKey] = $this->hasMany() ? $relatedNodes : $relatedNodes->first();
            }

            $node->setNodeRelations($relations);
        }

        return $mainNodes;
    }

    /**
     * @param NestableModel $main
     * @param ElonestCollection $allRelatedNodes
     * @return Collection
     */
    protected function filterRelationsForMain(NestableModel $main, ElonestCollection $allRelatedNodes): ElonestCollection
    {
        // TODO: process with OR condition later.
        $conditions = $this->relatedConditions();
        $relatedNodes = $allRelatedNodes->filter(function (NestableModel $node) use ($conditions) {
            $result = false;
            foreach ($conditions as $condition) {
                $key = $condition[0];
                if (count($condition) == 2) {
                    $result = $this->mapWithOperator($node->$key, $condition[1]);
                } else {
                    $result = $this->mapWithOperator($node->$key, $condition[2], $condition[1]);
                }

                if (!$result) break;
            }
            return $result;
        });

        return $relatedNodes->sortBy($main->getLeftKey());
    }

    /**
     * @param string|null $operator
     * @param mixed $retrieved Value of model field
     * @param mixed $value Value will be compared
     * @return bool
     */
    protected function mapWithOperator(mixed $retrieved, mixed $value, ?string $operator = null): bool
    {
        switch ($operator) {
            default:
            case '=':
            case '==':  return $retrieved == $value;
            case '!=':
            case '<>':  return $retrieved != $value;
            case '<':   return $retrieved < $value;
            case '>':   return $retrieved > $value;
            case '<=':  return $retrieved <= $value;
            case '>=':  return $retrieved >= $value;
            case '===': return $retrieved === $value;
            case '!==': return $retrieved !== $value;
            case '<=>': return $retrieved <=> $value;
        }
    }
}
