<?php

namespace Minh164\EloNest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Minh164\EloNest\Collections\NestedString;
use Minh164\EloNest\Exceptions\ElonestException;

/**
 * Layer handle actions about inspecting and repairing logics.
 */
class InspectRepairBase
{
    /**
     * Get lazy set by separating chunks.
     *
     * @param NestableModel $sampleModel
     * @param int $originalNumber
     * @return array
     */
    public function getLazyChunks(NestableModel $sampleModel, int $originalNumber): array
    {
        $chunks = [];
        $currentId = 0;
        while (true) {
            $nodes = $sampleModel
                ->newQueryWithoutScopes()
                ->whereOriginalNumber($originalNumber)
                ->where($sampleModel->getPrimaryName(), '>', $currentId)
                ->orderBy($sampleModel->getPrimaryName())
                ->limit(100000)
                ->cursor();
            if (!$nodes->count()) {
                break;
            }

            $chunks[] = $nodes;

            /* @var NestableModel $lastNode */
            $lastNode = $nodes->last();
            $currentId = $lastNode->getPrimaryId();
        }

        return $chunks;
    }

    /**
     * Get lazy set order by Left value by separating chunks.
     *
     * @param NestableModel $sampleModel
     * @param int $originalNumber
     * @return array
     */
    public function getLazyChunksByLeft(NestableModel $sampleModel, int $originalNumber): array
    {
        $chunks = [];
        $offset = 0;
        while (true) {
            $nodes = $sampleModel
                ->newQueryWithoutScopes()
                ->whereOriginalNumber($originalNumber)
                ->orderBy($sampleModel->getLeftKey())
                ->offset($offset)
                ->limit(10)
                ->cursor();
            if (!$nodes->count()) {
                break;
            }

            $chunks[] = $nodes;
            $offset += 10;
        }

        return $chunks;
    }

    /**
     * @param array $lazyChunks
     * @return NestedString
     */
    public function makeStringByLazyChunks(array $lazyChunks, bool $hasLeftRight = false): NestedString
    {
        $string = '';
        $lrString = '';
        /* @var LazyCollection $chunk */
        foreach ($lazyChunks as $chunk) {
            /* @var NestableModel $node */
            foreach ($chunk as $node) {
                $string .= NestedString::newNode($node->getPrimaryId(), $node->getParentId());
                if ($hasLeftRight) {
                    $lrString .= NestedString::newLeftRight($node->getPrimaryId(), $node->getLeftValue(), $node->getRightValue());
                }
            }
        }

        return new NestedString($string, $lrString);
    }

    /**
     * Find and make nested string for repairing.
     *
     * @param NestableModel $sampleModel
     * @param int $originalNumber
     * @return void
     * @throws ElonestException
     */
    public function makeStringAndRepair(NestableModel $sampleModel, int $originalNumber): void
    {
        $lazyChunks = $this->getLazyChunks($sampleModel, $originalNumber);
        $nestedString = $this->makeStringByLazyChunks($lazyChunks);
        $nestedString = $nestedString->nestByAllWithChunk();

        $this->updateLeftRight($nestedString, $sampleModel);
    }

    /**
     * Repair for nested string.
     *
     * @param NestableModel $sampleModel
     * @param NestedString $nestedString
     * @param int $originalNumber
     * @return void
     * @throws ElonestException
     */
    public function repairForString(NestableModel $sampleModel, NestedString $nestedString): void
    {
        $this->updateLeftRight($nestedString, $sampleModel);
    }

    /**
     * @param NestedString $nestedString
     * @param NestableModel $sampleModel
     * @return void
     * @throws ElonestException
     */
    protected function updateLeftRight(NestedString $nestedString, NestableModel $sampleModel): void
    {
        if (!$root = $nestedString->findRoot()) {
            throw new ElonestException("Cannot find root node in set");
        }

        $value = 1;
        $depth = 0;
        $this->buildUpdateQueriesByString(
            $root,
            $nestedString,
            $value,
            $depth,
            $leftQueries,
            $rightQueries,
            $depthQueries
        );

        $updateSql = "
            UPDATE {$sampleModel->getTable()}
            SET
            {$sampleModel->getLeftKey()} = CASE {$sampleModel->getPrimaryName()} {$leftQueries} END,
            {$sampleModel->getRightKey()} = CASE {$sampleModel->getPrimaryName()} {$rightQueries} END,
            {$sampleModel->getDepthKey()} = CASE {$sampleModel->getPrimaryName()} {$depthQueries} END
        ";

        // Execute single query to update all nodes.
        DB::statement($updateSql);
    }

    /**
     * @param string $current
     * @param NestedString $string
     * @param int|null $value
     * @param int|null $depth
     * @param string|null $leftQueries
     * @param string|null $rightQueries
     * @param string|null $depthQueries
     * @return void
     * @throws ElonestException
     */
    protected function buildUpdateQueriesByString(
        string $current,
        NestedString &$string,
        ?int &$value,
        ?int $depth,
        ?string &$leftQueries = '',
        ?string &$rightQueries = '',
        ?string &$depthQueries = ''
    ): void
    {
        NestedString::validateNode($current);
        $string->deleteChainNodes($current);
        $currentId = $string->getId($current);

        $leftQueries .= " WHEN $currentId THEN $value";
        $value++;

        $depthQueries .= " WHEN $currentId THEN $depth";

        while ($next = $string->getByIndex(0)) {
            // If Next is NOT a child of Current, break and set right value for Current.
            if ($currentId != $string->getParentId($next)) {
                break;
            }

            // If Next is child of Current.
            $this->buildUpdateQueriesByString(
                $next,
                $string,
                $value,
                $depth + 1,
                $leftQueries,
                $rightQueries,
                $depthQueries
            );
        }

        $rightQueries .= " WHEN $currentId THEN $value";
        $value++;

        if (!$next) {
            return;
        }

        // Next is sibling of Current.
        if ($string->getParentId($next) == $string->getParentId($current)) {
            $string->deleteChainNodes($next);
            $this->buildUpdateQueriesByString(
                $next,
                $string,
                $value,
                $depth,
                $leftQueries,
                $rightQueries,
                $depthQueries
            );
        }
    }
}
