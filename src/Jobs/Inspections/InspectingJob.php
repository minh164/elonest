<?php

namespace Minh164\EloNest\Jobs\Inspections;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minh164\EloNest\Collections\NestedCollection;
use Minh164\EloNest\Collections\NestedString;
use Minh164\EloNest\Constants\InspectionConstant;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\InspectRepairBase;
use Minh164\EloNest\Jobs\Job;
use Minh164\EloNest\ModelSetInspection;
use Minh164\EloNest\NestableModel;
use Exception;
use Minh164\EloNest\Traits\NestableClassValidationTrait;

/**
 * Inspecting model set job by string.
 */
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
    protected ?string $missing = null;

    /**
     * Inspection errors.
     */
    protected ?string $errors = null;
    protected string $modelName;
    protected NestableModel $sampleModel;
    protected NestedString $nestedString;

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
            DB::beginTransaction();

            $this->sampleModel = new $this->modelName();
            $this->setNestedString($base);

            $root = $this->nestedString->findRoot();
            if (!$root) {
                $this->rootPrimaryId = null;
                $this->setError("Does not have root in set", InspectionConstant::DOESNT_HAVE_ROOT_CODE);
                $inspection = $this->createInspection();
                self::logInfo("Inspecting model set completely. Inspection ID: $inspection->id");
                return;
            }

            $this->rootPrimaryId = $this->nestedString->getId($root);
            $value = 0;
            $this->checkLeftRightByString($root, $value);

            // Nest all for nodes which have not been accessed.
            $nestedString = $this->nestedString->nestByAllWithChunk();
            [$missing , $root] = $nestedString->findMissingAndRoot();

            if (count($missing)) {
                $this->setMissing($missing);
            }

            $inspection = $this->createInspection();
            self::logInfo("Inspecting model set completely. Inspection ID: $inspection->id");

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            self::logError($e->getMessage());
        }
    }

    /**
     * Create inspection history.
     *
     * @return ModelSetInspection
     */
    protected function createInspection(): ModelSetInspection
    {
        $inspection = new ModelSetInspection();
        $inspection->class = $this->modelName;
        $inspection->original_number = $this->originalNumber;
        $inspection->is_broken = strlen($this->errors) > 0;
        $inspection->root_id = $this->rootPrimaryId;
        $inspection->missing_ids = !empty($this->missing) ? trim($this->missing, ',') : null;
        $inspection->errors = $this->errors ? "[" . trim($this->errors, ',') . "]" : null;
        $inspection->is_resolved = !$inspection->is_broken;
        $inspection->description = !$inspection->is_broken ? "Model set is correct" : "Has something's wrong";
        $inspection->from_inspection_id = null;
        $inspection->save();

        return $inspection;
    }

    /**
     * @param InspectRepairBase $base
     * @return void
     */
    protected function setNestedString(InspectRepairBase $base): void
    {
        $lazyChunks = $base->getLazyChunksByLeft($this->sampleModel, $this->originalNumber);
        $this->nestedString = $base->makeStringByLazyChunks($lazyChunks, true);
    }

    /**
     * @param array $missing
     * @return void
     * @throws ElonestException
     */
    protected function setMissing(array $missing): void
    {
        foreach ($missing as $node) {
            $primaryId = $this->nestedString->getId($node);
            $this->missing .= $primaryId . ',';
            $this->setError(
                'Missing parent',
                InspectionConstant::MISSING_PARENT_CODE,
                [
                    'primary_id' => $primaryId,
                    'missing_parent_id' => $this->nestedString->getParentId($node),
                ]
            );
        }
    }

    /**
     * Validate left right values of set by string.
     *
     * @param string $node
     * @param int $value
     * @return void
     * @throws ElonestException
     */
    protected function checkLeftRightByString(string $node, int &$value = 0): void
    {
        NestedString::validateNode($node);
        $id = $this->nestedString->getId($node);
        [$left, $right] = $this->nestedString->getLeftRight($id);

        $value++;
        if ($left != $value) {
            $this->setError(
                'Incorrect Left',
                InspectionConstant::WRONG_LEFT_CODE,
                [
                    'primary_id' => $id,
                    'current' => $left,
                    'must_be' => $value,
                ]
            );
        }

        $children = $this->nestedString->findChildrenString($id);
        if (!$children) {
            $value++;
            if ($right != $value) {
                $this->setError(
                    'Incorrect Right',
                    InspectionConstant::WRONG_RIGHT_CODE,
                    [
                        'primary_id' => $id,
                        'current' => $right,
                        'must_be' => $value,
                    ]
                );
            }

            return;
        }

        $this->nestedString->deleteNodes($children);
        $childrenString = new NestedString($children);
        while ($child = $childrenString->getByIndex(0)) {
            $childrenString->deleteChainNodes($child);
            $this->checkLeftRightByString($child, $value);
        }

        $value++;
        if ($right != $value) {
            $this->setError(
                'Incorrect Right',
                InspectionConstant::WRONG_RIGHT_CODE,
                [
                    'primary_id' => $id,
                    'current' => $right,
                    'must_be' => $value,
                ]
            );
        }
    }

    /**
     * @param string $message
     * @param int $code
     * @param array|null $data
     * @return void
     */
    protected function setError(string $message, int $code, ?array $data = []): void
    {
        $this->errors .= json_encode([
            'message' => $message,
            'code' => $code,
            'data' => $data,
        ]) . ',';
    }
}
