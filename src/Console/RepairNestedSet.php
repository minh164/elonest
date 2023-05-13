<?php

namespace Minh164\EloNest\Console;

use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Minh164\EloNest\NestableModel;

class RepairNestedSet
{
    /**
     * @var Collection
     */
    private Collection $nodes;

    public function run(): void
    {
        $model = new Node();
        // Clear all values of Right and Left.
        Node::query()
            ->where('original_number', 1)
            ->update([
                $model->getLeftKey() => null,
                $model->getRightKey() => null,
            ]);

        $this->nodes = Node::query()
            ->where('original_number', 1)
            ->get();

        /** @var Node $rootNode */
        $rootNode = $this->nodes->where($model->getParentKey(), $this->nodes->min($model->getParentKey()))->first();

        $value = 0;
        $leftSql = [];
        $rightSql = [];
        $this->repairNode($rootNode, $value, $leftSql, $rightSql);

        // Update multiple nodes with single query.
        $leftString = implode(' ', $leftSql);
        $rightString = implode(' ', $rightSql);
        DB::statement("
            UPDATE nodes
            SET
            {$model->getLeftKey()} = CASE {$model->getPrimaryName()} {$leftString} END,
            {$model->getRightKey()} = CASE {$model->getPrimaryName()} {$rightString} END
        ");

        dd($leftSql, $rightSql);

    }

    private function repairNode(NestableModel $node, int &$value, array &$leftSql, array &$rightSql): void
    {
        $value++;
        $node->lft = $value;
        $leftSql[] = " WHEN {$node->id} THEN $value";

        $children = $this->nodes->where('parent_id', $node->getPrimaryId());
        if ($children->count() <= 0) {
            $node->rgt = $node->lft + 1;
            $value = $node->rgt;
            $rightSql[] = " WHEN {$node->id} THEN $value";

            return;
        }

        /** @var Node $child */
        foreach ($children as $child) {
            $this->repairNode($child, $value, $leftSql, $rightSql);
        }

        $node->rgt = $value + 1;
        $value = $node->rgt;
        $rightSql[] = " WHEN {$node->id} THEN $value";
    }
}
