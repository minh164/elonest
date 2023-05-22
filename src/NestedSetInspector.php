<?php

namespace Minh164\EloNest;

use Minh164\EloNest\Collections\NestedCollection;
use Minh164\EloNest\Constants\InspectionConstant;
use Minh164\EloNest\Exceptions\ElonestException;
use Exception;

/**
 * Layer handle checking something's wrong in nested set.
 */
class NestedSetInspector
{
    /**
     * @var bool
     */
    protected bool $hasRoot = false;

    /**
     * @var bool
     */
    protected bool $isBroken = false;

    /**
     * Number of nodes don't find its parent.
     *
     * @var int
     */
    protected int $missingParents = 0;

    /**
     * Errors list after checking.
     * @var array
     */
    protected array $errors = [];

    /**
     * @var NestableModel
     */
    protected NestableModel $model;

    /**
     * @var NestedCollection
     */
    protected NestedCollection $nodes;

    /**
     * @param string $nestableModelName Classname of model which is belonged NestableModel
     * @throws ElonestException
     */
    public function __construct(string $nestableModelName)
    {
        if (!class_exists($nestableModelName)) {
            throw new ElonestException("Does not exist $nestableModelName model");
        }

        $model = new $nestableModelName;
        if (!$model instanceof NestableModel) {
            throw new ElonestException("$nestableModelName model is not a " . NestableModel::class . " instance");
        }

        $this->model = $model;
    }

    /**
     * Inspect nested model tree by original number.
     *
     * @param int $originalNumber
     * @return void
     * @throws Exception
     */
    public function inspectModelSet(int $originalNumber): void
    {
        // Reset errors before checking.
        $this->errors = [];

        $nodeSet = [];
        $this->model->newQuery()
            ->whereOriginalNumber($originalNumber)
            ->chunkById(1000, function ($nodes) use (&$nodeSet) {
                /* @var NestableModel $node */
                foreach ($nodes as $node) {
                    $nodeSet[] = $node->toArray();
                }

            }, $this->model->getPrimaryName());

        $this->nodes = new NestedCollection($nodeSet);
        $this->nodes->setNestedByAll();

        $this->setMissingParents();

        $root = $this->getRoot();
        if ($root) {
            $this->hasRoot = true;
            $value = 0;
            $this->checkLeftRight($root, $value);
        } else {
            $this->setError("Does not have root in set", InspectionConstant::DOESNT_HAVE_ROOT_CODE);
        }

        $this->setIsBroken();
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return bool
     */
    public function isBroken(): bool
    {
        return $this->isBroken;
    }

    /**
     * @return bool
     */
    public function hasRoot(): bool
    {
        return $this->hasRoot;
    }

    /**
     * @return void
     */
    protected function setIsBroken(): void
    {
        $this->isBroken = count($this->errors);
    }

    /**
     * @return void
     */
    protected function setMissingParents(): void
    {
        $missingParents = $this->getMissingParents();
        $count = count($missingParents);
        if (!$count) {
            return;
        }

        $this->missingParents = $count;
        foreach ($missingParents as $node) {
            $this->setError(
                'Missing parent',
                InspectionConstant::MISSING_PARENT_CODE,
                [
                    'primary_id' => $node[$this->model->getPrimaryName()],
                    'missing_parent_id' => $node[$this->model->getParentKey()],
                ]
            );
        }
    }

    /**
     * Get root node.
     *
     * @return null|array
     */
    protected function getRoot(): ?array
    {
        $root = $this->nodes->where($this->model->getParentKey(), $this->model->getRootNumber())->first();

        return $root ?? null;
    }

    /**
     * Count total of missing parents.
     *
     * @return array
     */
    protected function getMissingParents(): array
    {
        return $this->nodes->where($this->model->getParentKey(), '!=', $this->model->getRootNumber())->toArray();
    }

    /**
     * @param string $message
     * @param int $code
     * @param array|null $data
     * @return void
     */
    protected function setError(string $message, int $code, ?array $data = []): void
    {
        $this->errors[] = [
            'message' => $message,
            'code' => $code,
            'data' => $data,
        ];
    }

    /**
     * Checking Left and Right value of nested nodes.
     *
     * @param array $startNode
     * @param int $value
     * @return void
     */
    protected function checkLeftRight(array $startNode, int &$value = 0): void
    {
        $value++;
        if ($startNode[$this->model->getLeftKey()] != $value) {
            $this->setError(
                'Left has wrong value',
                InspectionConstant::WRONG_LEFT_CODE,
                [
                    'primary_id' => $startNode[$this->model->getPrimaryName()],
                    'current' => $startNode[$this->model->getLeftKey()],
                    'must_be' => $value,
                ]
            );
        }

        $children = $startNode['child_items'] ?? [];
        if (count($children) <= 0) {
            $value++;
            if ($startNode[$this->model->getRightKey()] != $value) {
                $this->setError(
                    'Right has wrong value',
                    InspectionConstant::WRONG_RIGHT_CODE,
                    [
                        'primary_id' => $startNode[$this->model->getPrimaryName()],
                        'current' => $startNode[$this->model->getRightKey()],
                        'must_be' => $value,
                    ]
                );
            }

            return;
        }

        foreach ($children as $child) {
            $this->checkLeftRight($child, $value);
        }

        $value++;
        if ($startNode[$this->model->getRightKey()] != $value) {
            $this->setError(
                'Right has wrong value',
                InspectionConstant::WRONG_RIGHT_CODE,
                [
                    'primary_id' => $startNode[$this->model->getPrimaryName()],
                    'current' => $startNode[$this->model->getRightKey()],
                    'must_be' => $value,
                ]
            );
        }
    }
}
