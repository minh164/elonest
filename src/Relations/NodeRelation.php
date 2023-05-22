<?php

namespace Minh164\EloNest\Relations;

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
     * Query to execute get relation.
     * NOTICE: need return NULL if having error conditions. It will be help getQuery() or execute() return NULL value instead of throwing Exception.
     *
     * @return Builder|null
     */
    abstract protected function relatedQuery(ElonestBuilder $query): ?Builder;

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

    /**
     * Return relations query.
     *
     * @param ElonestBuilder $query
     * @return Builder
     * @throws ElonestException
     */
    public function getQuery(ElonestBuilder $query): Builder
    {
        try {
            $query = $this->relatedQuery($query);

            if (empty($query) || empty($this->model->getOriginalNumberValue())) {
                throw new Exception("relatedQuery() is null or Original Number is missing");
            }

            return $query->whereOriginalNumber($this->model->getOriginalNumberValue());
        } catch (Exception $e) {
            throw new ElonestException("Node relation has something was wrong: " . $e->getMessage());
        }
    }

    /**
     * Return result query.
     *
     * @return mixed
     */
    public function execute(): mixed
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
        return $this->first();
    }
}
