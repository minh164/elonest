<?php

namespace Minh164\EloNest\Jobs\Inspections;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minh164\EloNest\Collections\NestedCollection;
use Minh164\EloNest\Constants\InspectionConstant;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\InspectRepairBase;
use Minh164\EloNest\Jobs\Job;
use Minh164\EloNest\ModelSetInspection;
use Minh164\EloNest\NestableModel;
use Exception;
use Minh164\EloNest\Traits\NestableClassValidationTrait;

class InspectingJob extends Job implements ShouldQueue
{
    use NestableClassValidationTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Trigger repairing job after inspecting (if model set is broken).
     */
    protected bool $processRepair = false;
    protected int $originalNumber;
    protected ?int $rootPrimaryId;

    /**
     * Number of nodes don't find its parent, DOES NOT include root.
     */
    protected array $missingParents = [];
    protected array $errors = [];
    protected string $modelName;
    protected NestableModel $sampleModel;
    protected NestedCollection $models;

    /**
     * Create a new job instance.
     *
     * @param string $nestableModelClass Classname of model which is belonged NestableModel
     * @param int $originalNumber Original number of model set
     * @param bool $processRepair Trigger processing repair after inspect completely
     * @throws ElonestException
     */
    public function __construct(string $nestableModelClass, int $originalNumber, bool $processRepair = false)
    {
        $this->validateClass($nestableModelClass);

        //$this->sampleModel = $model; DON'T set model at here, due to when laravel handles job, it cannot serialize model.
        $this->modelName = $nestableModelClass;
        $this->originalNumber = $originalNumber;
        $this->processRepair = $processRepair;
    }

    /**
     * Execute the job. Inspect nested model tree by original number.
     */
    public function handle(InspectRepairBase $base): void
    {
        try {
            // Reset errors before checking.
            $this->errors = [];

            $this->sampleModel = new $this->modelName();
            $this->setModelList();
            $this->setMissingParents();

            $root = $this->findRoot();
            if ($root) {
                $this->rootPrimaryId = $root[$this->sampleModel->getPrimaryName()];
                $value = 0;
                $this->checkLeftRight($root, $value);
            } else {
                $this->rootPrimaryId = null;
                $this->setError("Does not have root in set", InspectionConstant::DOESNT_HAVE_ROOT_CODE);
            }

            $inspection = $this->createInspection();
            self::logInfo("Inspecting model set completely. Inspection ID: $inspection->id");
        } catch (Exception $e) {
            self::logError($e->getMessage());
        }
    }

    protected function createInspection(): ModelSetInspection
    {
        $inspection = new ModelSetInspection();
        $inspection->class = $this->modelName;
        $inspection->original_number = $this->originalNumber;
        $inspection->is_broken = count($this->errors) > 0;
        $inspection->root_id = $this->rootPrimaryId;
        $inspection->missing_ids = !empty($this->missingParents) ? implode(',', $this->missingParents) : null;
        $inspection->errors = !empty($this->errors) ? json_encode($this->errors) : null;
        $inspection->is_resolved = !$inspection->is_broken;
        $inspection->description = !$inspection->is_broken ? "Model set is correct" : "Has something's wrong";
        $inspection->from_inspection_id = null;
        $inspection->save();

        return $inspection;
    }

    /**
     * Query and set nested models.
     *
     * @return void
     * @throws Exception
     */
    protected function setModelList(): void
    {
        $nodeSet = [];
        $this->sampleModel->newQuery()
            ->whereOriginalNumber($this->originalNumber)
            ->chunkById(1000, function ($nodes) use (&$nodeSet) {
                /* @var NestableModel $node */
                foreach ($nodes as $node) {
                    $nodeSet[] = $node->toArray();
                }
            }, $this->sampleModel->getPrimaryName());

        $this->models = new NestedCollection($nodeSet);
        $this->models->setNestedByAll();
    }

    /**
     * @return void
     */
    protected function setMissingParents(): void
    {
        $missingParents = $this->getMissingParents();
        if (!count($missingParents)) {
            return;
        }

        foreach ($missingParents as $node) {
            $primaryId = $node[$this->sampleModel->getPrimaryName()];
            $this->missingParents[] = $primaryId;
            $this->setError(
                'Missing parent',
                InspectionConstant::MISSING_PARENT_CODE,
                [
                    'primary_id' => $primaryId,
                    'missing_parent_id' => $node[$this->sampleModel->getParentKey()],
                ]
            );
        }
    }

    /**
     * Get root node.
     *
     * @return null|array
     */
    protected function findRoot(): ?array
    {
        $root = $this->models->where($this->sampleModel->getParentKey(), $this->sampleModel->getRootNumber())->first();

        return $root ?? null;
    }

    /**
     * Count total of missing parents.
     *
     * @return array
     */
    protected function getMissingParents(): array
    {
        return $this->models->where($this->sampleModel->getParentKey(), '!=', $this->sampleModel->getRootNumber())->toArray();
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
        if ($startNode[$this->sampleModel->getLeftKey()] != $value) {
            $this->setError(
                'Left has wrong value',
                InspectionConstant::WRONG_LEFT_CODE,
                [
                    'primary_id' => $startNode[$this->sampleModel->getPrimaryName()],
                    'current' => $startNode[$this->sampleModel->getLeftKey()],
                    'must_be' => $value,
                ]
            );
        }

        $children = $startNode['child_items'] ?? [];
        if (count($children) <= 0) {
            $value++;
            if ($startNode[$this->sampleModel->getRightKey()] != $value) {
                $this->setError(
                    'Right has wrong value',
                    InspectionConstant::WRONG_RIGHT_CODE,
                    [
                        'primary_id' => $startNode[$this->sampleModel->getPrimaryName()],
                        'current' => $startNode[$this->sampleModel->getRightKey()],
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
        if ($startNode[$this->sampleModel->getRightKey()] != $value) {
            $this->setError(
                'Right has wrong value',
                InspectionConstant::WRONG_RIGHT_CODE,
                [
                    'primary_id' => $startNode[$this->sampleModel->getPrimaryName()],
                    'current' => $startNode[$this->sampleModel->getRightKey()],
                    'must_be' => $value,
                ]
            );
        }
    }
}
