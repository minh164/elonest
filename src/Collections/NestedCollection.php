<?php

namespace Minh164\EloNest\Collections;

use Exception;
use Illuminate\Support\Collection;
use Minh164\EloNest\Constants\ItemStateConstant;
use Minh164\EloNest\Nestable;

/**
 * Layer data about collection is nestable.
 */
class NestedCollection extends Collection implements Nestable
{
    /**
     * Property to keep base items from beginning.
     *
     * @var array
     */
    protected array $originalItems;

    /**
     * Property to mark main values of items which were accessed when mutation was processing.
     *
     * @var array
     */
    protected array $accessedMains = [];

    /**
     * Property to mark main values of items which were NOT accessed when mutation was processing.
     *
     * @var array
     */
    protected array $missingMains = [];

    /**
     * State to determine mutation of current items.
     *
     * @var string
     */
    protected string $itemState;

    /**
     * @param array $items
     */
    public function __construct($items = [])
    {
        parent::__construct($items);

        $this->originalItems = $items;
        $this->setItemState(ItemStateConstant::ORIGINAL);
    }

    /**
     * Return nested item list as array.
     *
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return array
     * @throws Exception
     */
    public function toNestedArray(string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): array
    {
        return $this->getNestedItemArray($this->items, $mainKey, $nestedKey, $childrenKey);
    }

    /**
     * @return string
     */
    public function getItemState(): string
    {
        return $this->itemState;
    }

    /**
     * @param string $state
     * @return void
     */
    protected function setItemState(string $state): void
    {
        $this->itemState = $state;
    }

    /**
     * @param string|int $mainKeyToDiff Main key (likely primary key in database) to compare
     * @return void
     */
    protected function setMissingMains(string|int $mainKeyToDiff): void
    {
        $beforeValues = array_column($this->originalItems, $mainKeyToDiff);
        $afterValues = $this->accessedMains;

        $this->missingMains = array_values(array_diff($beforeValues, $afterValues));
    }

    /**
     * @return array
     */
    public function getMissingMains(): array
    {
        return $this->missingMains;
    }

    /**
     * @return array
     */
    public function getOriginal(): array
    {
        return $this->originalItems;
    }

    /**
     * Reset collection to original items.
     *
     * @return void
     */
    public function setOriginal(): void
    {
        $this->items = $this->originalItems;
        $this->setItemState(ItemStateConstant::ORIGINAL);
        $this->resetAccessedAndMissing();
    }

    /**
     * Return a list is arranged all items in collection to nested depths.
     * NOTICE: If occurs any error while mutation is processing, state it will be reset to original.
     * Logic:
     *      1. Loop through all same depth nodes (assuming they are root) => Root list.
     *      2. Find children of each node, use recursion to nest multiple depths.
     *      3. Each of node children which is found by parent (in step 2), this child will be removed from Root list (due to it has been already set into its parent).
     *      4. Finally, Root list just has nodes which are root OR not found by their parent.
     *
     * Ex:
     * Input: [A, B.1, B, A.1, B.2, C, B.2.1]
     * Output: [
     *      [A, Children => [A.1]],
     *      [B, Children => [
     *          [B.1, Children => []],
     *          [B.2, Children => [B.2.1]]
     *      ],
     *      [C, Children => []],
     * ]
     *
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return static
     * @throws Exception
     */
    public function newNestedByAll(string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): static
    {
        $this->setOriginal();
        $parentGroup = $this->groupBy($nestedKey);
        $newCollection = new static([]);

        foreach ($this->items as $item) {
            $parentValue = $this->getTargetInItem($item, $nestedKey);
            $mainValue = $this->getTargetInItem($item, $mainKey);
            $root = $this->findByMain($mainValue, $mainKey);
            if (!$root) {
                continue;
            }

            // Set nested children for root.
            $root = $this->nestForParentsV2(new static([$root]), $parentGroup, $mainKey, $nestedKey, $childrenKey, true)[0];

            $newCollection->put($parentValue, $root);
        }

        // Only get root or missing parents.
        return $newCollection->intersectByKeys($parentGroup)->values();
    }

    /**
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return void
     * @throws Exception
     */
    public function setNestedByAll(string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): void
    {
        $this->items = $this->newNestedByAll($mainKey, $nestedKey, $childrenKey)->toArray();

        $this->setItemState(ItemStateConstant::BY_ALL);
    }

    /**
     * Return a list is arranged items to nested depths by root value.
     * NOTICE: If occurs any error while mutation is processing, state it will be reset to original.
     *
     * @param int|string $rootValue Value of root which is used to determine starting level
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return static
     * @throws Exception
     */
    public function newNestedByRoot(int|string $rootValue = 0, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): static
    {
        $this->setOriginal();

        // Get all root items.
        $roots = $this->where($nestedKey, '=', $rootValue);

        return $this->nestForParentsV2($roots, $this->groupBy($nestedKey), $mainKey, $nestedKey, $childrenKey);
    }

    /**
     * @param int|string $rootValue
     * @param string $mainKey
     * @param string $nestedKey
     * @param string $childrenKey
     * @return void
     * @throws Exception
     */
    public function setNestedByRoot(int|string $rootValue = 0, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): void
    {
        $this->items = $this->newNestedByRoot($rootValue, $mainKey, $nestedKey, $childrenKey)->toArray();

        $this->accessedMains = array_values($this->accessedMains);
        $this->setMissingMains($mainKey);
        $this->setItemState(ItemStateConstant::BY_ROOT);
    }

    /**
     * Return a list is arranged items to nested depths with parent item is first depth.
     * NOTICE: If occurs any error while mutation is processing, state it will be reset to original.
     *
     * @param int|array $parentValue Main value of parent which be used to determine starting level
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return static
     * @throws Exception
     */
    public function newNestedForParent(int|array $parentValue, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): static
    {
        $this->setOriginal();
        $parentValue = is_array($parentValue) ? $parentValue : [$parentValue];

        // Get all parent items.
        $parents = $this->whereIn($mainKey, $parentValue);

        return $this->nestForParentsV2($parents, $this->groupBy($nestedKey), $mainKey, $nestedKey, $childrenKey);
    }

    /**
     * @param int|array $parentValue
     * @param string $mainKey
     * @param string $nestedKey
     * @param string $childrenKey
     * @return void
     * @throws Exception
     */
    public function setNestedForParent(int|array $parentValue, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): void
    {
        $this->items = $this->newNestedForParent($parentValue, $mainKey, $nestedKey, $childrenKey)->toArray();

        $this->accessedMains = array_values($this->accessedMains);
        $this->setMissingMains($mainKey);
        $this->setItemState(ItemStateConstant::FOR_PARENT);
    }

    /**
     * Convert all item to array.
     *
     * @param array $items Item list
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return array
     * @throws Exception
     */
    protected function getNestedItemArray(array $items, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): array
    {
        foreach ($items as $key => $item) {
            if (!$this->isTargetExisted($item, $childrenKey)) {
                $items[$key] = $this->getArrayableItems($item);
                continue;
            }

            $childItems = $this->getTargetInItem($item, $childrenKey);
            $childItems = $this->getArrayableItems($childItems);

            // If item has children then continue with each child item.
            if (count($childItems) > 0) {
                $childItems = $this->getNestedItemArray($childItems, $mainKey, $nestedKey, $childrenKey);
            }

            $item = $this->getArrayableItems($item);
            $item[$childrenKey] = $childItems ?? null;
            $items[$key] = $item;
        }

        return array_values($items);
    }

    /**
     * Get child items of parent item by parent's main value.
     *
     * @param int|string $mainValue Main value of parent item want to get its child items.
     * @param string $parentKey Parent key name of child item.
     * @return static
     * @throws Exception
     */
    protected function findChildrenByParent(int|string $mainValue, string $parentKey = 'id'): static
    {
        return $this->where($parentKey, '=', $mainValue);
    }

    /**
     * Get item by main value.
     *
     * @param int|string $mainValue
     * @param string $mainKey
     * @return mixed
     */
    protected function findByMain(int|string $mainValue, string $mainKey): mixed
    {
        return $this->where($mainKey, $mainValue)->first();
    }

    /**
     * Remove item by main value.
     *
     * @param int|string $mainValue
     * @param string $mainKey
     * @return void
     */
    protected function removeByMain(int|string $mainValue, string $mainKey = 'id'): void
    {
        $item = $this->where($mainKey, $mainValue)->toArray();
        if (!count($item)) {
            return;
        }

        $itemKey = array_keys($item);
        $this->forget($itemKey[0]);
    }

    /**
     * Ver 1 - Process set nested children for parent list.
     *
     * @param NestedCollection $items Parent item list
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @param bool $accessOnce Enable mode for each child item will be accessed only once
     * @return NestedCollection
     * @throws Exception
     */
    protected function nestForParentsV1(NestedCollection $items, string $mainKey, string $nestedKey, string $childrenKey, bool $accessOnce = false): static
    {
        foreach ($items as $key => $item) {
            $mainValue = $this->getTargetInItem($item, $mainKey);

            // Mark item was accessed.
            $this->accessedMains[$mainValue] = $mainValue;

            // Remove accessed child item from main list.
            if ($accessOnce) {
                $this->removeByMain($mainValue, $mainKey);
            }

            $childItems = $this->findChildrenByParent($mainValue, $nestedKey);
            // If item has children then continue with each child item.
            if (count($childItems)) {
                $childItems = $this->nestForParentsV1($childItems, $mainKey, $nestedKey, $childrenKey, $accessOnce);
            }

            if (is_array($item)) {
                $item[$childrenKey] = new static($childItems->toArray());
            } elseif (is_object($item)) {
                $item->$childrenKey = new static($childItems->toArray());
            }
            $items->put($key, $item);
        }

        return $items->values();
    }


    /**
     * Ver 2 - Process set nested children for parent list.
     *
     * @param NestedCollection $items Parent item list
     * @param NestedCollection $parentGroup
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @param bool $accessOnce Enable mode for each child item will be accessed only once
     * @return NestedCollection
     * @throws Exception
     */
    protected function nestForParentsV2(
        NestedCollection $items,
        NestedCollection $parentGroup,
        string $mainKey,
        string $nestedKey,
        string $childrenKey,
        bool $accessOnce = false
    ): static
    {
        foreach ($items as $key => $item) {
            $mainValue = $this->getTargetInItem($item, $mainKey);
            // Mark item was accessed.
            $this->accessedMains[$mainValue] = $mainValue;

            /* @var NestedCollection $children */
            $children = $parentGroup->get($mainValue);
            if ($children?->count() > 0) {
                // Remove accessed group.
                if ($accessOnce) {
                    $parentGroup->forget($mainValue);
                }

                $children = $this->nestForParentsV2($children, $parentGroup, $mainKey, $nestedKey, $childrenKey);
            }

            if (is_array($item)) {
                $item[$childrenKey] = !empty($children) ? new static($children->toArray()) : null;
            } elseif (is_object($item)) {
                $item->$childrenKey = !empty($children) ? new static($children->toArray()) : null;
            }
            $items->put($key, $item);
        }

        return $items->values();
    }

    /**
     * Another solution for setNestedByAll, but it seems complicated.
     * @param NestedCollection $items
     * @param string $mainKey
     * @param string $nestedKey
     * @param string $childrenKey
     * @param bool $isRoot
     * @return $this
     * @throws Exception
     */
    protected function nestForAll(NestedCollection $items, string $mainKey, string $nestedKey, string $childrenKey, bool $isRoot = true): static
    {
        foreach ($items as $key => $item) {
            $mainValue = $this->getTargetInItem($item, $mainKey);
            if (!$mainValue) {
                continue;
            }

            // Determines
            $isExistedInRoots = (bool) $this->where($mainKey, $mainValue)->first();
            if (!$isRoot) {
                // If current node is in child loop.
                if ($isExistedInRoots) {
                    // If child is existed in Root list, it will be added in children list of parent and be removed from Root list.
                    $this->forget($key);
                } else {
                    // If child is not existed in Root list that means this child was processed, it will be skipped.
                    continue;
                }
            } elseif (!$isExistedInRoots) {
                // If current node is in root loop AND it was processed, it will be removed from Root list and skipped.
                $items->forget($key);
                continue;
            }

            // Find and set children for parent.
            $childItems = $this->findChildrenByParent($mainValue, $nestedKey);
            if (count($childItems)) {
                $childItems = $this->nestForAll($childItems, $mainKey, $nestedKey, $childrenKey, false);
            }

            if (is_array($item)) {
                $item[$childrenKey] = $childItems;
            } elseif (is_object($item)) {
                $item->$childrenKey = $childItems;
            }
            $items->put($key, $item);
        }

        return $items->values();
    }

    /**
     * @param mixed $item
     * @param string|int $key
     * @return bool
     */
    protected function isTargetExisted(mixed $item, string|int $key): bool
    {
        $isArray = is_array($item);

        return $isArray ? array_key_exists($key, $item) : property_exists($item, $key);
    }

    /**
     * Get target in item by key.
     *
     * @param mixed $item
     * @param string|int $key
     * @return mixed
     * @throws Exception
     */
    protected function getTargetInItem(mixed $item, string|int $key): mixed
    {
        $isArray = is_array($item);

        if ($isArray && !array_key_exists($key, $item)) {
            throw new Exception("Undefined key: \"$key\" in array");
        }

        if (!$isArray && !property_exists($item, $key)) {
            throw new Exception("Undefined property: \"$key\" in " . $item::class . " object");
        }

        return $isArray ? $item[$key] : $item->$key;
    }

    /**
     * @return void
     */
    protected function resetAccessedAndMissing(): void
    {
        $this->accessedMains = [];
        $this->missingMains = [];
    }
}
