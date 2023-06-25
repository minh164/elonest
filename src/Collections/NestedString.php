<?php

namespace Minh164\EloNest\Collections;

use Minh164\EloNest\Exceptions\ElonestException;
use function PHPUnit\Framework\matches;

/**
 * Nested logic with string by regex.
 */
class NestedString
{
    public const TEMP_NODE = '<>';
    public const NESTED_SIGN = '=';
    public const MISSING_SIGN = '-';

    /**
     * Internal string.
     */
    protected string $string;

    /**
     * Left Right string.
     */
    protected string $lrString;

    /**
     * Value of root node ("parent" of root).
     */
    protected int $rootValue = 0;

    /**
     * @param string $string
     * @param string|null $lrString
     */
    public function __construct(string $string, ?string $lrString = '')
    {
        $this->string = $string;
        $this->lrString = $lrString;
    }

    /**
     * @return string
     */
    public function getString(): string
    {
        return $this->string;
    }

    /**
     * @param string $newString
     * @return void
     */
    public function renew(string $newString): void
    {
        $this->string = $newString;
    }

    /**
     * Replace old node with new node in main string.
     *
     * @param string $oldNode
     * @param string $newNode
     * @return void
     * @throws ElonestException
     */
    public function replace(string $oldNode, string $newNode): void
    {
        static::validateNode($oldNode);
        static::validateNode($newNode);
        $this->string = str_replace($oldNode, $newNode, $this->string);
    }

    /**
     * @return int
     */
    public function getRootValue(): int
    {
        return $this->rootValue;
    }

    /**
     * @param int $value
     * @return void
     */
    public function setRootValue(int $value): void
    {
        $this->rootValue = $value;
    }

    protected static function getSignsPattern(): string
    {
        return "[" . self::NESTED_SIGN . "|" . self::MISSING_SIGN . "]";
    }

    /**
     * Get node by ID.
     *
     * @param int $primaryId
     * @return string|null
     */
    public function find(int $primaryId): ?string
    {
        $pattern = "/<$primaryId" . $this->getSignsPattern() . "[0-9]+>/";
        preg_match($pattern, $this->string, $matches);
        return $matches[0] ?? null;
    }

    /**
     * Get root.
     *
     * @return string|null
     */
    public function findRoot(): ?string
    {
        $pattern = "/<[0-9]+" . $this->getSignsPattern() . "$this->rootValue>/";
        preg_match($pattern, $this->string, $matches);
        return $matches[0] ?? null;
    }

    /**
     * Get children of node.
     *
     * @param int $parentId
     * @return array
     */
    public function findChildren(int $parentId): array
    {
        $pattern = "/(<[0-9]+" . $this->getSignsPattern() . "$parentId>)/";
        preg_match_all($pattern, $this->string, $matches);
        return $matches[0];
    }

    /**
     * Get children of node with string result.
     *
     * @param int $parentId
     * @return string|null
     */
    public function findChildrenString(int $parentId): ?string
    {
        $clone = clone $this;
        $chunks = $clone->chunkToArrays(200);
        $childrenString = null;

        /* @var static $chunk */
        foreach ($chunks as $chunk) {
            $children = $chunk->findChildren($parentId);
            $childrenString .= !empty($children) ? implode('', $children) : null;
        }
        return $childrenString;
    }

    /**
     * Get nested children of node with nested main string.
     * @param int $parentId
     * @return string|null
     */
    public function findNestedChildren(int $parentId): ?string
    {
        $firstPattern = "(<([0-9]+)" . $this->getSignsPattern() . "$parentId>)";
        $secondPattern = "(.*<([0-9]+)" . $this->getSignsPattern() . "$parentId>)*";
        $pattern = "/" . $firstPattern . $secondPattern . "/";

        preg_match($pattern, $this->string, $matches);
        $lastChildId = !empty($matches[4]) ? $matches[4] : (!empty($matches[2]) ? $matches[2] : null);
        if ($lastChildId) {
            $nested = $this->findNestedChildren($lastChildId);
            $matches[0] .= $nested;
        }

        return $matches[0] ?? null;
    }

    /**
     * Get siblings of node.
     *
     * @param int $parentId
     * @return array
     */
    public function findSiblings(int $parentId): array
    {
        $pattern = "/(<[0-9]+" . $this->getSignsPattern() . "$parentId>)/";
        preg_match_all($pattern, $this->string, $matches);
        return $matches[0];
    }

    /**
     * Get missing parent nodes.
     *
     * @return array
     */
    public function findMissing(): array
    {
        preg_match_all("/(<[0-9]+" . self::MISSING_SIGN . "[0-9]+>)/", $this->string, $matches);
        return $matches[0];
    }

    /**
     * Get missing and root are separated.
     *
     * @return array [0]: missing array, [1]: root string
     */
    public function findMissingAndRoot(): array
    {
        $missing = $this->findMissing();
        $root = $this->findRoot();
        return [array_diff($missing, [$root]), $root];
    }

    /**
     * Get missing only.
     *
     * @return array
     */
    public function findMissingWithoutRoot(): array
    {
        return $this->findMissingAndRoot()[0];
    }

    /**
     * @return int
     */
    public function count(?string $externalString = null): int
    {
        $string = !empty($externalString) ? $externalString : $this->string;
        return preg_match_all("/(<[0-9]+" . $this->getSignsPattern() . "[0-9]+>)/", $string);
    }

    /**
     * Get node by calculated index.
     * NOTICE: preg_match has been limited by 4500.
     *
     * @param int $index
     * @param string|null $externalString
     * @return string|null
     */
    public function getByIndex(int $index, ?string $externalString = null): ?string
    {
        $string = !empty($externalString) ? $externalString : $this->string;
//        $pattern = "/(<[0-9]+-[0-9]+>){" . $index + 1 ."}/";
        $pattern = "/(<.*?>){" . $index + 1 ."}/";
        preg_match($pattern, $string, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Loop and return new each value.
     *
     * @param callable $callback
     * @return string
     */
    public function map(callable $callback): string
    {
        $newString = null;
        $max = $this->count();
        $isBreak = false;
        for ($index=0; $index<$max; $index++) {
            if ($isBreak) {
                break;
            }
            $node = $this->getByIndex($index);
            $newString .= $callback($node, $index, $isBreak);
        }

        return $newString;
    }

    /**
     * Loop and return new each value with external string.
     *
     * @param callable $callback
     * @param string $string
     * @return string
     */
    public function mapInString(callable $callback, string $string): string
    {
        $newString = null;
        $max = $this->count($string);
        for ($index=0; $index<$max; $index++) {
            $node = $this->getByIndex($index, $string);
            $newString .= $callback($node, $index);
        }

        return $newString;
    }

    /**
     * Loop with internal string.
     *
     * @param callable $callback
     * @return void
     */
    public function each(callable $callback): void
    {
        $max = $this->count();
        for ($index=0; $index<$max; $index++) {
            $node = $this->getByIndex($index);
            $callback($node, $index);
        }
    }

    /**
     * Loop with external string.
     *
     * @param callable $callback
     * @param string $string
     * @return void
     */
    public function eachInString(callable $callback, string $string): void
    {
        $max = $this->count($string);
        for ($index=0; $index<$max; $index++) {
            $node = $this->getByIndex($index, $string);
            $callback($node, $index);
        }
    }

    /**
     * Make new node.
     *
     * @param int $primaryId
     * @param int $parentId
     * @return string
     */
    public static function newNode(int $primaryId, int $parentId): string
    {
        return "<$primaryId-$parentId>";
    }

    /**
     * Make new node with left and right value.
     *
     * @param int $primaryId
     * @param int $left
     * @param int $right
     * @return string
     */
    public static function newLeftRight(int $primaryId, int $left, int $right): string
    {
        return "<$primaryId:$left-$right>";
    }

    /**
     * Determines node is valid syntax.
     *
     * @param string $node
     * @return bool
     */
    public static function isNodeValid(string $node): bool
    {
        $pattern = "/^<[0-9]+" . self::getSignsPattern() . "[0-9]+>$/";
        return preg_match($pattern, $node);
    }

    /**
     * @param string $node
     * @return void
     * @throws ElonestException
     */
    public static function validateNode(string $node): void
    {
        if (!self::isNodeValid($node)) {
            throw new ElonestException("Node string syntax is incorrect. It must be: <[0-9]+-[0-9]+>");
        }
    }

    /**
     * Get primary ID in node.
     *
     * @param string $node
     * @return int|null
     * @throws ElonestException
     */
    public function getId(string $node): ?int
    {
        $this->validateNode($node);
        preg_match("/^<([0-9]+)/", $node, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Get parent ID in node.
     *
     * @param string $node
     * @return int|null
     * @throws ElonestException
     */
    public function getParentId(string $node): ?int
    {
        $this->validateNode($node);
        preg_match("/([0-9]+)>$/", $node, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Get left right values of node.
     *
     * @param int $primaryId
     * @return array [0]: left value, [1]: right value
     */
    public function getLeftRight(int $primaryId): array
    {
        $pattern = "/<$primaryId:([0-9]+)-([0-9]+)>/";
        preg_match($pattern, $this->lrString, $matches);
        return [
            !empty($matches[1]) ? (int) $matches[1] : null,
            !empty($matches[2]) ? (int) $matches[2] : null,
        ];
    }

    /**
     * Add new nodes to the end of main string.
     *
     * @param string $nodes
     * @return void
     * @throws ElonestException
     */
    public function append(string $nodes): void
    {
        $this->string .= $nodes;
    }

    /**
     * Add new nodes to the beginning of main string.
     *
     * @param string $nodes
     * @return void
     * @throws ElonestException
     */
    public function prepend(string $nodes): void
    {
        $this->string = $nodes . $this->string;
    }

    /**
     * Add new nodes to the beginning specified node of main string.
     *
     * @param string $nodes
     * @param string $fromNode
     * @return void
     * @throws ElonestException
     */
    public function prependAt(string $nodes, string $fromNode): void
    {
        $pos = strpos($this->string, $fromNode);
        $this->string = substr_replace($this->string, $nodes, $pos, 0);
    }

    /**
     * @param string $node
     * @return int|null
     * @throws ElonestException
     */
    public function getSign(string $node): ?string
    {
        preg_match("/(" . $this->getSignsPattern() . ")/", $node, $matches);
        return $matches[1] ?? null;
    }

    /**
     * @param string $node
     * @return bool
     * @throws ElonestException
     */
    public function isNested(string $node): bool
    {
        return $this->getSign($node) == self::NESTED_SIGN;
    }

    /**
     * @param string $node
     * @return bool
     */
    public function isTemp(string $node): bool
    {
        return $node == self::TEMP_NODE;
    }

    /**
     * @param string $node
     * @return bool
     * @throws ElonestException
     */
    public function isMissing(string $node): bool
    {
        return $this->getSign($node) == self::MISSING_SIGN;
    }

    /**
     * @param string $nodes
     * @return void
     */
    public function deleteNodes(string $nodes): void
    {
        $this->eachInString(function ($node) {
            $this->string = str_replace($node, '', $this->string);
        }, $nodes);
    }

    /**
     * @param string $chainNodes
     * @return void
     */
    public function deleteChainNodes(string $chainNodes): void
    {
        $this->string = str_replace($chainNodes, '', $this->string);
    }

    /**
     * Deletes nodes for temporarily.
     *
     * @param string $deleteStrings
     * @return void
     */
    protected function tempDelete(string $deleteStrings): void
    {
        $this->eachInString(function ($node) {
            $this->string = str_replace($node, self::TEMP_NODE, $this->string);
        }, $deleteStrings);
    }

    /**
     * Final delete temp nodes.
     *
     * @return void
     */
    protected function deleteTempNodes(): void
    {
        $this->string = str_replace(self::TEMP_NODE, '', $this->string);
    }

    /**
     * Change parent ID of node.
     *
     * @param string $node
     * @param int $newParentId
     * @return void
     * @throws ElonestException
     */
    public function changeParent(string &$node, int $newParentId): void
    {
        $oldParentId = $this->getParentId($node);
        $node = str_replace($oldParentId, $newParentId, $node);
    }

    /**
     * Change parent and replace in main string.
     *
     * @param string $node
     * @param int $newParentId
     * @return void
     * @throws ElonestException
     */
    public function changeParentAndReplace(string &$node, int $newParentId): void
    {
        $oldNode = $node;
        $this->changeParent($node, $newParentId);
        $this->replace($oldNode, $node);
    }

    /**
     * Change parent of node and nest it to parent.
     *
     * @param string $node
     * @param int $newParentId
     * @return void
     * @throws ElonestException
     */
    public function changeParentAndNest(string &$node, int $newParentId): void
    {
        $this->changeParentAndReplace($node, $newParentId);
        $children = $this->findNestedChildren($this->getId($node));
        $nodeAndChildren = $node . $children;
        $this->nestForParent($newParentId, $nodeAndChildren);
    }

    /**
     * @param string $nodes
     * @return void
     */
    public function toNestedSign(string &$nodes): void
    {
        $nodes = str_replace(self::MISSING_SIGN, self::NESTED_SIGN, $nodes);
    }

    /**
     * Change to nested sign for nodes in main string.
     *
     * @param string $nodes
     * @return void
     * @throws ElonestException
     */
    public function toNestedSignForInternal(string $nodes): void
    {
        $this->eachInString(function ($node) {
            $this->validateNode($node);
            $oldNode = $node;
            $this->toNestedSign($node);
            $this->string = str_replace($oldNode, $node, $this->string);
        }, $nodes);
    }

    /**
     * @param string $nodes
     * @return void
     */
    public function toMissingSign(string &$nodes): void
    {
        $nodes = str_replace(self::NESTED_SIGN, self::MISSING_SIGN, $nodes);
    }

    /**
     * @param string $node
     * @return array [0]: sliced part, [1]: remain part
     * @throws ElonestException
     */
    public function sliceFrom(string $node): array
    {
        $this->validateNode($node);
        $nodePos = strpos($this->string, $node);
        return [
            substr($this->string, $nodePos),
            substr($this->string, 0, $nodePos)
        ];
    }

    /**
     * @param string $node
     * @return array [0]: sliced part, [1]: remain part
     * @throws ElonestException
     */
    public function sliceTo(string $node): array
    {
        $this->validateNode($node);
        $nodePos = strpos($this->string, $node) + strlen($node);
        return [
            substr($this->string, 0, $nodePos),
            substr($this->string, $nodePos)
        ];
    }

    /**
     * Separate to chunk array.
     *
     * @param int $size
     * @return array
     */
    public function chunkToArrays(int $size): array
    {
        $total = 0;
        $all = $this->count();
        $arrays = [];
        while ($total < $all) {
            $count = 0;
            $chunk = $this->map(function ($item, $index, &$isBreak) use (&$count, $size) {
                if (++$count == $size) {
                    $isBreak = true;
                }
                return $item;
            });
            $arrays[] = new static($chunk);
            $this->string = str_replace($chunk, '', $this->string);
            $total += $count;
        }

        return $arrays;
    }

    /**
     * Nest children for parent list.
     *
     * @param string $parentsString
     * @return string
     * @throws ElonestException
     */
    public function setNestedForParents(string $parentsString): string
    {
        return $this->mapInString(function ($parent) {
            $this->validateNode($parent);
            $children = $this->findChildren($this->getId($parent));
            $children = count($children) > 0 ? implode('', $children) : null;
            if ($children) {
                $children = $this->setNestedForParents($children);
            }

            return $parent . $children;
        }, $parentsString);
    }

    /**
     * Put nested children to parent.
     *
     * @param int $parentId
     * @param string $children
     * @return void
     * @throws ElonestException
     */
    public function nestForParent(int $parentId, string $children): void
    {
        $parent = $this->find($parentId);
        $parentPos = strpos($this->string, $parent);
        if (!$parentPos && $parentPos < 0) {
            return;
        }

        // Remove children (if they are old instead of new) before nest they to parent.
        $this->deleteChainNodes($children);

        $parentLen = strlen($parent);
        $this->string = substr_replace($this->string, $children, $parentPos + $parentLen, 0);
        $this->toNestedSignForInternal($children);
    }

    /**
     * Nest children for all nodes (from "flat" list to depth list).
     *
     * @return void
     * @throws ElonestException
     */
    public function setNestedByAll(): void
    {
        $this->nestForAll();
        $this->deleteTempNodes();
    }

    /**
     * @param string|null $nestedString
     * @return void
     * @throws ElonestException
     */
    protected function nestForAll(?string $nestedString = null): void
    {
        $currentIndex = 0;
        $count = $this->count($nestedString);
        while ($currentIndex < $count) {
            if (!empty($nestedString)) {
                // If loop is children list.
                $node = $this->getByIndex($currentIndex, $nestedString);
            } else {
                // If loop is root list, only get Missing nodes.
                $node = $this->getByIndex($currentIndex);
                if ($node && $this->isNested($node)) {
                    $currentIndex++;
                    continue;
                }
            }

            if (!$node || $this->isTemp($node)) {
                $currentIndex++;
                continue;
            }

            $id = $this->getId($node);
            $children = $this->findChildren($id);
            $children = count($children) > 0 ? implode('', $children) : null;
            if ($children) {
                $this->tempDelete($children);
                $this->toNestedSign($children);
                $this->nestForParent($id, $children);
                $this->nestForAll($children);
            }
            $currentIndex++;
        }
    }

    /**
     * Nest all nodes by chunks.
     *
     * @param int|null $chunkSize
     * @return NestedString
     * @throws ElonestException
     */
    public function nestByAllWithChunk(?int $chunkSize = 100): NestedString
    {
        $chunks = $this->chunkToArrays($chunkSize);
        $newString = new NestedString('');
        /** @var NestedString $chunk */
        foreach ($chunks as $index => $chunk) {
            $chunk->each(function ($node) use (&$newString, $index) {
                $id = $newString->getId($node);
                $children = $newString->findNestedChildren($id);

                $parentId = $newString->getParentId($node);
                $parent = $newString->find($parentId);

                $siblings = $newString->findSiblings($parentId);
                $firstSibling = !empty($siblings) ? $siblings[0] : null;

                if ($children && $parent) {
                    // If current node finds both children and parent.

                    $newString->toNestedSign($node);
                    // Remove old children position.
                    $newString->deleteNodes($children);
                    // Nest children to node.
                    $nodeAndChildren = $node . $children;
                    // Nest node to parent.
                    $newString->nestForParent($parentId, $nodeAndChildren);
                    $newString->toNestedSignForInternal($children);
                } elseif ($parent && !$children) {
                    // If current node finds only parent.
                    $newString->toNestedSign($node);
                    $newString->nestForParent($parentId, $node);
                } elseif ($children && !$parent) {
                    // If current node finds only children.
                    $newString->deleteNodes($children);
                    $newString->append($node);
                    $newString->nestForParent($id, $children);
                    $newString->toNestedSignForInternal($children);
                } elseif ($firstSibling && !$parent && !$children) {
                    // If current node finds only sibling.
                    $newString->prependAt($node, $firstSibling);
                } else {
                    // If current node doesn't find anyone.
                    $newString->append($node);
                }
            });
        }

        return $newString;
    }
}
