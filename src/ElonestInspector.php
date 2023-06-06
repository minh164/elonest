<?php

namespace Minh164\EloNest;

use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\Jobs\Inspections\InspectingJob;
use Minh164\EloNest\Traits\NestableClassValidationTrait;

/**
 * Inspector will manipulate inspections and repairing actions for a model set.
 */
class ElonestInspector
{
    use NestableClassValidationTrait;

    protected int $originalNumber;
    protected NestableModel $sampleModel;
    protected ?ModelSetInspection $newestInspection = null;

    /**
     * @param string $nestableModelClass Classname of model which is belonged NestableModel
     * @param int $originalNumber Original number of model set
     * @throws ElonestException
     */
    public function __construct(string $nestableModelClass, int $originalNumber)
    {
        $this->validateClass($nestableModelClass);
        $this->originalNumber = $originalNumber;
        $this->sampleModel = new $nestableModelClass();
        $this->findAndSetNewestInspection();
    }

    /**
     * Get the latest inspection.
     * @return ModelSetInspection|null
     */
    public function findNewestInspection(): ?ModelSetInspection
    {
        return ModelSetInspection::query()
            ->where(ModelSetInspection::ORIGINAL_NUMBER, $this->originalNumber)
            ->where(ModelSetInspection::CLASS_NAME, $this->sampleModel::class)
            ->orderByDesc(ModelSetInspection::ID)
            ->first();
    }

    /**
     * Get the latest inspection and set for current inspection.
     * @return ModelSetInspection|null
     */
    public function findAndSetNewestInspection(): ?ModelSetInspection
    {
        return $this->newestInspection = $this->findNewestInspection();
    }

    /**
     * Determines model set has inspected.
     * @return bool
     */
    public function hasInspected(): bool
    {
        return !is_null($this->newestInspection);
    }

    protected function throwIfHasNotInspected(): void
    {
        if (!$this->hasInspected()) {
            throw new ElonestException("Current model set has not inspected");
        }
    }

    /**
     * Determines model set is broken.
     * @return bool
     * @throws ElonestException
     */
    public function isBrokenSet(): bool
    {
        $this->throwIfHasNotInspected();
        return $this->newestInspection->is_broken;
    }

    /**
     * Determines model set is resolved.
     * @return bool
     * @throws ElonestException
     */
    public function isResolved(): bool
    {
        $this->throwIfHasNotInspected();
        return $this->newestInspection->is_resolved;
    }

    /**
     * Make a new inspection.
     * @return void
     */
    public function inspect(): void
    {
        InspectingJob::dispatchSync($this->sampleModel::class, $this->originalNumber);
        $this->findAndSetNewestInspection();
    }

    /**
     * Make new inspection by asynchronous process.
     * @return void
     */
    public function inspectByQueue(): void
    {
        InspectingJob::dispatch($this->sampleModel::class, $this->originalNumber);
    }
}
