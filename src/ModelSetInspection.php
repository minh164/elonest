<?php

namespace Minh164\EloNest;

use Illuminate\Database\Eloquent\Model;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\Jobs\Inspections\InspectingJob;

/**
 * @property int $id
 * @property string $class
 * @property int $original_number
 * @property bool $is_broken
 * @property int $root_id
 * @property string $missing_ids
 * @property string $errors
 * @property bool $is_resolved
 * @property string $description
 * @property int $from_inspection_id
 *
 * @property-read array $error_array
 * @property-read array $missing_array
 */
class ModelSetInspection extends Model
{
    public const ID = 'id';
    public const CLASS_NAME = 'class';
    public const ORIGINAL_NUMBER = 'original_number';
    public const IS_BROKEN = 'is_broken';
    public const ROOT_ID = 'root_id';
    public const MISSING_IDS = 'missing_ids';
    public const ERRORS = 'errors';
    public const IS_RESOLVED = 'is_resolved';
    public const DESCRIPTION = 'description';
    public const FROM_INSPECTION_ID = 'from_inspection_id';

    /**
     * @return array
     */
    public function getErrorArrayAttribute(): array
    {
        return empty($this->errors) ? [] : json_decode($this->errors, true);
    }

    /**
     * @return array
     */
    public function getMissingArrayAttribute(): array
    {
        return empty($this->missing_ids) ? [] : explode(',', $this->missing_ids);
    }

    /**
     * Query to find existed root by information from inspection.
     * @return NestableModel|null
     */
    protected function findRootByInspection(): ?NestableModel
    {
        if (!$rootId = $this->inspection->root_id) {
            return null;
        }

        return $this->sampleModel
            ->newQuery()
            ->whereRoot()
            ->whereOriginalNumber($this->inspection->original_number)
            ->find($rootId);
    }

    /**
     * @return bool
     */
    public function hasExistedRoot(): bool
    {
        return !empty($this->rootModel);
    }

    /**
     * @return void
     * @throws ElonestException
     */
    public function validateRoot(): void
    {
        if (!$this->hasExistedRoot()) {
            throw new ElonestException("Root is not existed in model set, need to create new root for repairing");
        }

        // Root must be an instance of Class in inspection.
        if (!$this->rootModel instanceof $this->sampleModel) {
            throw new ElonestException('$rootModel is not a instance of ' . $this->sampleModel::class);
        }

        if (!$rootModel->exists || !$rootModel->isRoot()) {
            throw new ElonestException('$rootModel is not existed or is not a root model');
        }
    }

    /**
     * Re-inspecting model set by current inspection information and processing repair.
     */
    public function newInspectionAndRepair(): void
    {
        $this->validateRoot();
        InspectingJob::dispatchSync($this->inspection->class, $this->inspection->original_number, true);
    }
}
