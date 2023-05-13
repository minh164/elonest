<?php

namespace Minh164\EloNest;

use Illuminate\Database\Eloquent\Collection;
use Exception;
use Minh164\EloNest\Constants\ItemStateConstant;

/**
 * Layer data about collection is nestable.
 */
class NestedCollection extends Collection implements Nestable
{
    /**
     * Property to keep base items from beginning.
     *
     * @var array|mixed
     */
    protected array $originalItems;

    /**
     * Property to mark items which were accessed when mutation was processing.
     *
     * @var array
     */
    protected array $accessedItems = [];

    /**
     * Property to mark items which were NOT accessed when mutation was processing.
     *
     * @var array
     */
    protected array $missingItems = [];

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
     * @param string|int $keyToDiff Main key (likely primary key in database) to compare
     * @return void
     */
    protected function setMissingItems(string|int $keyToDiff): void
    {
        $beforeValues = array_column($this->originalItems, null, $keyToDiff);
        $afterValues = array_column($this->accessedItems, null, $keyToDiff);

        $this->missingItems = array_values(array_diff_key($beforeValues, $afterValues));
    }

    /**
     * @return array
     */
    public function getMissingItems(): array
    {
        return $this->missingItems;
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
     * Arrange items to nested depths by roots.
     * NOTICE: If occurs any error while mutation is processing, state it will be reset to original.
     *
     * Ex:
     * Input: [A, B.1, B, A.1, B.2, C, B.2.1]
     * Output: [
     *      [0 => A, Children => [0 => A.1]],
     *      [1 => B, Children => [
     *          [0 => B.1],
     *          [1 => B.2, Children => [0 => B.2.1]]
     *      ],
     *      [2 => C, Children => []],
     * ]
     *
     * @param int $rootValue Value of root which is used to determine starting level
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return void
     * @throws Exception
     */
    public function setNestedByRoot(int $rootValue, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): void
    {
        $this->setOriginal();

        // Get all root items.
        $roots = $this->where($nestedKey, '=', $rootValue);

        // Recursion to set children for each item.
        $this->items = $this->getNestedItems($roots, $mainKey, $nestedKey, $childrenKey)->values()->toArray();

        $this->setMissingItems($mainKey);
        $this->setItemState(ItemStateConstant::BY_ROOT);
    }

    /**
     * Arrange items to nested depths by parents.
     *
     * @param int|array $parentValue Main value of parent which be used to determine starting level
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return void
     * @throws Exception
     */
    public function setNestedByParent(int|array $parentValue, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): void
    {
        $this->setOriginal();
        $parentValue = is_array($parentValue) ? $parentValue : [$parentValue];

        // Get all parent items.
        $parents = $this->whereIn($mainKey, $parentValue);
        $this->items = $this->getNestedItems($parents, $mainKey, $nestedKey, $childrenKey)->values()->toArray();

        $this->setMissingItems($mainKey);
        $this->setItemState(ItemStateConstant::BY_PARENT);
    }

    /**
     * Load a set of node relationships onto the collection.
     *
     * @param mixed $relations
     * @return $this
     * @throws Exception
     */
    public function loadNodeRelations(mixed $relations): static
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $model = $this->first();
            if (!$model instanceof NestableModel) {
                throw new Exception('Object is not a ' . NestableModel::class . ' instance');
            }

            $query = $model->newQuery()->withNodes($relations);
            $query->eagerLoadNodeRelations($this);
        }

        return $this;
    }

    /**
     * Convert all item to array.
     *
     * @param array $items Item list
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return array
     */
    protected function getNestedItemArray(array $items, string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): array
    {
        foreach ($items as $key => $item) {
            $childItems = $this->getTargetInItem($item, $childrenKey);
            $childItems = $this->getArrayableItems($childItems);

            // If item has children then continue with each child item.
            if (count($childItems) > 0) {
                $childItems = $this->getNestedItemArray($childItems, $mainKey, $nestedKey, $childrenKey);
            }

            $item = $this->getArrayableItems($item);
            $item[$childrenKey] = $childItems ?? [];
            $items[$key] = $item;
        }

        return array_values($items);
    }

    /**
     * Get child items of main item by its value.
     *
     * @param mixed $itemValue Value of main item want to get its child items.
     * @param string $parentKey Parent key name of child item.
     * @return Collection
     * @throws Exception
     */
    protected function getChildrenOfItemValue(mixed $itemValue, string $parentKey = 'parent_id'): Collection
    {
        if (!is_int($itemValue) && !is_string($itemValue)) {
            throw new Exception('Value of item must be integer or string type');
        }

        return $this->where($parentKey, '=', $itemValue);
    }

    /**
     * Return item list is nested.
     *
     * @param Collection $items Main item list
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     * @return Collection
     * @throws Exception
     */
    protected function getNestedItems(Collection $items, string $mainKey, string $nestedKey, string $childrenKey = 'child_items'): Collection
    {
        foreach ($items as $key => $item) {
            // Mark item was accessed.
            $this->accessedItems[] = $item;

            $itemValue = $this->getTargetInItem($item, $mainKey);
            if (!$itemValue) {
                continue;
            }

            $childItems = $this->getChildrenOfItemValue($itemValue, $nestedKey);

            // If item has children then continue with each child item.
            if (count($childItems)) {
                $childItems = $this->getNestedItems($childItems, $mainKey, $nestedKey);
            }

            if (is_array($item)) {
                $item[$childrenKey] = $childItems;
            } elseif (is_object($item)) {
                $item->$childrenKey = $childItems;
            }
            $items->put($key, $item);
        }

        return $items;
    }

    /**
     * Get target in item by key.
     *
     * @param mixed $item
     * @param string|int $key
     * @return mixed
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
        $this->accessedItems = [];
        $this->missingItems = [];
    }
}
