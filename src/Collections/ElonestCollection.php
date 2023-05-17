<?php

namespace Minh164\EloNest\Collections;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

            $query = $model->newQuery()->withNodes($relations);
            $query->eagerLoadNodeRelations($this);
        }

        return $this;
    }
}
