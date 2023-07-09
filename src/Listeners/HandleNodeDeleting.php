<?php

namespace Minh164\EloNest\Listeners;

use Exception;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\NestableModel;

/**
 * Node deleting listener.
 */
class HandleNodeDeleting
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
        try {
            DB::beginTransaction();

            $this->deleteChildren();
            $this->updateAnotherNodes();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new ElonestException($e->getMessage());
        }
    }

    /**
     * Delete children of node.
     *
     * @return void
     */
    protected function deleteChildren(): void
    {
        $this->model->newQueryWithoutScopes()
            ->whereChildren($this->model->getLeftValue(), $this->model->getRightValue())
            ->whereOriginalNumber($this->model->getOriginalNumberValue())
            ->delete();
    }

    /**
     * Update left and right of another nodes.
     *
     * @return void
     */
    protected function updateAnotherNodes(): void
    {
        // Amount to subtract current parent.
        $currentSubtractionAmount = $this->model->getRightValue() - $this->model->getLeftValue() + 1;

        // Decreasing left of other right nodes.
        $this->model->newInstance()->newQueryWithoutScopes()
            ->whereOriginalNumber($this->model->getOriginalNumberValue())
            ->where($this->model->getLeftKey(), '>', $this->model->getRightValue())
            ->decrement($this->model->getLeftKey(), $currentSubtractionAmount);

        // Decreasing right of other right nodes.
        $this->model->newInstance()->newQueryWithoutScopes()
            ->whereOriginalNumber($this->model->getOriginalNumberValue())
            ->where($this->model->getRightKey(), '>', $this->model->getRightValue())
            ->decrement($this->model->getRightKey(), $currentSubtractionAmount);
    }
}
