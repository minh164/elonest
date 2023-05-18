<?php

namespace Minh164\EloNest\Relations;

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
     * Return result query.
     *
     * @return mixed
     */
    abstract public function execute(): mixed;

    /**
     * Query to execute get relation nodes.
     *
     * @return Builder
     */
    abstract public function getQuery(ElonestBuilder $query): Builder;

    /**
     * Mapping conditions to get children of each parent.
     *
     * @return MappingInfo
     */
    abstract public function getMapping(): MappingInfo;

    /**
     * @return bool
     */
    public function hasMany(): bool
    {
        return $this->hasMany;
    }
}
