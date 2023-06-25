<?php

namespace Minh164\EloNest\Listeners;

use Minh164\EloNest\NestableModel;

class HandleNodeCreating
{
    protected NestableModel $model;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(NestableModel $model): void
    {
        $this->model = $model;
        if ($parentId = $this->model->getParentId()) {
            $this->performAsChild($parentId);
        } else {
            $this->performAsRoot($this->model->getMaxOriginalNumber() + 1);
        }
    }

    /**
     * Perform node as a child.
     *
     * @param int $parentId ID of parent
     * @return void
     */
    protected function performAsChild(int $parentId): void
    {
        /* @var NestableModel $parent */
        $parent = $this->model->newInstance()::findOrFail($parentId);
        if ($parent) {
            $this->model->setLeftValue($parent->getRightValue());
            $this->model->setRightValue( $this->model->getLeftValue() + 1);
            $this->model->setParentId($parent->getPrimaryId());
            $this->model->setDepthValue($parent->getDepthValue() + 1);
            $this->model->setOriginalNumberValue($parent->getOriginalNumberValue());

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
    }

    /**
     * Perform node as a root.
     *
     * @param int $originalNumber
     * @return void
     */
    protected function performAsRoot(int $originalNumber): void
    {
        $this->model->setLeftValue(1);
        $this->model->setRightValue(2);
        $this->model->setOriginalNumberValue($originalNumber);

        // Use this if roots need chain together.
        //$this->chainLatestRoot($model, $insert);
    }
}
