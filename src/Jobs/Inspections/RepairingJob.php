<?php

namespace Minh164\EloNest\Jobs\Inspections;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\Collections\NestedString;
use Minh164\EloNest\ElonestInspector;
use Minh164\EloNest\Exceptions\ElonestException;
use Minh164\EloNest\InspectRepairBase;
use Minh164\EloNest\Jobs\Job;
use Minh164\EloNest\NestableModel;
use Minh164\EloNest\Traits\NestableClassValidationTrait;

class RepairingJob extends Job implements ShouldQueue
{
    use NestableClassValidationTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $originalNumber;
    protected string $modelName;
    protected NestableModel $sampleModel;
    protected NestedString $nestedString;

    /**
     * Create a new job instance.
     */
    public function __construct(string $nestableModelClass, int $originalNumber)
    {
        $this->validateClass($nestableModelClass);

        $this->modelName = $nestableModelClass;
        $this->originalNumber = $originalNumber;
    }

    /**
     * Execute the job.
     */
    public function handle(InspectRepairBase $base): void
    {
        try {
            DB::beginTransaction();

            $this->setNestedString($base);
            $root = $this->getRoot();

            $this->nestedString = $this->nestedString->nestByAllWithChunk();

            $missingList = $this->nestedString->findMissingWithoutRoot();
            if (count($missingList) > 0) {
                $this->nestMissingToRoot($root, $missingList);
            }

            $base->repairForString($this->sampleModel, $this->nestedString);

            // Make new inspection.
            $inspector = new ElonestInspector($this->modelName, $this->originalNumber);
            $inspector->inspect();

            self::logInfo("Repairing model set completely. Original number: {$this->originalNumber}");

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            self::logError("String repairing has error: " . $e->getMessage());
        }
    }

    /**
     * @param InspectRepairBase $base
     * @return void
     */
    protected function setNestedString(InspectRepairBase $base): void
    {
        /* @var NestableModel $sampleModel */
        $this->sampleModel = new $this->modelName();

        $lazyChunks = $base->getLazyChunks($this->sampleModel, $this->originalNumber);
        $this->nestedString = $base->makeStringByLazyChunks($lazyChunks);
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getRoot(): string
    {
        $root = $this->nestedString->findRoot();
        if (!$root) {
            // Create root if it's not existed.
            $rootModel = $this->sampleModel->newQuery()->firstOrCreateBackupRoot($this->originalNumber);
            $root = NestedString::newNode($rootModel->getPrimaryId(), $rootModel->getRootNumber());

            // Add root into string.
            $this->nestedString->prepend($root);
        }

        return $root;
    }

    /**
     * Change missing nodes to children of root.
     *
     * @param string $root
     * @param array $missingList
     * @return void
     * @throws ElonestException
     */
    protected function nestMissingToRoot(string $root, array $missingList): void
    {
        if (!count($missingList)) {
            return;
        }

        $rootId = $this->nestedString->getId($root);
        $missingIds = [];
        foreach ($missingList as $missing) {
            $missingIds[] = $this->nestedString->getId($missing);
            $this->nestedString->changeParentAndNest($missing, $rootId);
        }

        $this->sampleModel->newQuery()
            ->whereIn($this->sampleModel->getPrimaryName(), $missingIds)
            ->update([$this->sampleModel->getParentKey() => $rootId]);
    }
}
