<?php

namespace Minh164\EloNest\Console;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\NestableModel;

/**
 * Command to fix Left and Right nodes.
 */
class RepairCommand extends Command
{
    /**
     * @var Collection
     */
    private Collection $nodes;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elonest:node:repair
        {--model_namespace= : Namespace of nested model}
        {--original_number= : Original number of nested set node need be repaired}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-calculate Left and Right values of nodes
Example:
php artisan elonest:repair --model_namespace=App\Models\Category --original_number=1';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class = $this->option('model_name');
        $originalNumber = $this->option('original_number');

        if (!$class) {
            $this->error('Please provide --model_name'); exit();
        }
        if (!class_exists($class)) {
            $this->error("Does not exist $class model"); exit();
        }
        if (!$originalNumber) {
            $this->error('Please provide --original_number'); exit();

        }
        if (!is_int($originalNumber)) {
            $this->error('--original_number must be integer'); exit();
        }

        $model = new $class;
        if (!$model instanceof NestableModel) {
            $this->error("Does not exist $class model"); exit();
        }

        try {
            DB::beginTransaction();
            $this->processRepair($model);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error(json_encode(['error' => $e->getMessage()]));
            exit();
        }

        $this->info(json_encode(['message' => 'Nodes have repaired successfully!']));
        exit();
    }

    /**
     * Processing re-calculate nodes.
     *
     * @param NestableModel $model
     *
     * @return void
     */
    private function processRepair(NestableModel $model): void
    {
        // Clear all values of Right and Left.
        $model->newQuery()
            ->where($model->getOriginalNumberKey(), $this->option('original_number'))
            ->update([
                $model->getLeftKey() => null,
                $model->getRightKey() => null,
            ]);

        // Get all nodes need be repaired.
        $this->nodes = $model->newQuery()
            ->where($model->getOriginalNumberKey(), 1)
            ->get();

        // Get root node.
        $rootNode = $this->nodes->where($model->getParentKey(), 0)->first();

        // If root node is missed, new one will be created
        // and nodes have the lowest parent ID will be replaced with new created parent.


        $value = 0;
        $leftSql = [];
        $rightSql = [];
        $this->buildUpdateQueries($rootNode, $value, $leftSql, $rightSql);

        $leftString = implode(' ', $leftSql);
        $rightString = implode(' ', $rightSql);

        // Execute single query to update all nodes.
        DB::statement("
            UPDATE nodes
            SET
            {$model->getLeftKey()} = CASE {$model->getPrimaryName()} {$leftString} END,
            {$model->getRightKey()} = CASE {$model->getPrimaryName()} {$rightString} END
        ");
    }

    /**
     * Setup queries for update Left and Right values of nodes.
     *
     * @param NestableModel $startNode
     * @param int $value
     * @param array $leftSql
     * @param array $rightSql
     *
     * @return void
     */
    private function buildUpdateQueries(NestableModel $startNode, int &$value, array &$leftSql, array &$rightSql): void
    {
        $value++;
        $startNode->lft = $value;
        $leftSql[] = " WHEN {$startNode->id} THEN $value";

        $children = $this->nodes->where('parent_id', $startNode->getPrimaryId());
        if ($children->count() <= 0) {
            $startNode->rgt = $startNode->lft + 1;
            $value = $startNode->rgt;
            $rightSql[] = " WHEN {$startNode->id} THEN $value";

            return;
        }

        /** @var Node $child */
        foreach ($children as $child) {
            $this->buildUpdateQueries($child, $value, $leftSql, $rightSql);
        }

        $startNode->rgt = $value + 1;
        $value = $startNode->rgt;
        $rightSql[] = " WHEN {$startNode->id} THEN $value";
    }
}
