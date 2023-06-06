<?php

namespace Minh164\EloNest\Jobs\Inspections;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;
use Minh164\EloNest\Collections\ElonestCollection;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\Jobs\Job;
use Minh164\EloNest\ModelSetInspection;
use Minh164\EloNest\NestableModel;
use Minh164\EloNest\Traits\NestableClassValidationTrait;

class RepairingJob extends Job implements ShouldQueue
{
    use NestableClassValidationTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $inspectionId;

    /**
     * Determines model set need re-inspect, due to have changes about root or missing models.
     */
    protected bool $needInspect = false;

    /**
     * Create a new job instance.
     */
    public function __construct(int $inspectionId)
    {
        $this->inspectionId = $inspectionId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /* @var ModelSetInspection $inspection */
        $inspection = ModelSetInspection::findOrFail($this->inspectionId);
        if ($inspection->is_resolved) {
            self::logInfo("Inspection ID: {$inspection->id} was resolved before");
        }

        try {
            $this->validateClass($inspection->class);
            /* @var NestableModel $sampleModel */
            $sampleModel = new $inspection->class();

            $root = $this->getRoot($sampleModel, $inspection);
            $missing = $this->getMissing($sampleModel, $inspection);
            if ($missing->count() > 0) {
                $this->moveMissingToRoot($root, $missing);
                $this->needInspect = true;
            }

            // Make a new inspection if it is necessary for re-calculate left right of model set.
            if ($this->needInspect) {

            }
        } catch (Exception $e) {
            self::logError("Inspection ID: {$inspection->id} has error: " . $e->getMessage());
        }
    }

    /**
     * @param NestableModel $sampleModel
     * @param ModelSetInspection $inspection
     * @return NestableModel
     * @throws Exception
     */
    protected function getRoot(NestableModel $sampleModel, ModelSetInspection $inspection): NestableModel
    {
        if (!empty($inspection->root_id)) {
            $root = $sampleModel->newQuery()->findOrFail($inspection->root_id);
        } else {
            // Create root if it's not existed.
            $root = $sampleModel->newQuery()->firstOrCreateBackupRoot($inspection->original_number);
            $this->needInspect = true;
        }

        return $root;
    }

    /**
     * @param NestableModel $sampleModel
     * @param ModelSetInspection $inspection
     * @return ElonestCollection
     * @throws Exception
     */
    protected function getMissing(NestableModel $sampleModel, ModelSetInspection $inspection): ElonestCollection
    {
        $ids = $inspection->missing_array;
        $beforeCount = count($ids);
        if (!$beforeCount) {
            return new ElonestCollection([]);
        }

        $missing = $sampleModel->newQuery()->whereIn($sampleModel->getPrimaryName(), $ids)->get();
        $afterCount = $missing->count();
        if ($afterCount != $beforeCount) {
            throw new ElonestException("Missing models only get $afterCount of $beforeCount");
        }

        return $missing;
    }

    /**
     * Update parent of missing models by root.
     * @param NestableModel $root
     * @param ElonestCollection $missing
     * @return void
     * @throws ElonestException
     */
    protected function moveMissingToRoot(NestableModel $root, ElonestCollection $missing): void
    {
        $missingIds = $missing->pluck($root->getPrimaryName());
        if (!$missingIds->count()) {
            throw new ElonestException("Missing models were not found");
        }

        $root->newQuery()
            ->whereIn($root->getPrimaryName(), $missingIds)
            ->update([$root->getParentKey() => $root->getPrimaryId()]);
    }
}
