<?php

namespace Minh164\EloNest\Traits;

use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\NestableModel;

trait NestableClassValidationTrait
{
    protected function validateClass($nestableModelClass): void
    {
        if (!class_exists($nestableModelClass)) {
            throw new ElonestException("Does not exist $nestableModelClass model");
        }

        $model = new $nestableModelClass();
        if (!$model instanceof NestableModel) {
            throw new ElonestException("$nestableModelClass model is not a " . NestableModel::class . " instance");
        }
    }
}
