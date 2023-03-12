<?php

namespace Minh164\EloNest;

use Illuminate\Database\Eloquent\Collection;
use Exception;

/**
 * Layer data about collection is nestable.
 */
class NestedCollection extends Collection implements Nestable
{
    /**
     * @inheritdoc
     *
     * @param string $mainKey
     * @param string $nestedKey
     * @param string $childrenKey
     *
     * @return array
     *
     * @throws Exception
     */
    public function toNestedArray(string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): array
    {
        $minParentValue = $this->min($nestedKey);

        // Get all min parent items.
        $minParent = $this->where($nestedKey, '=', $minParentValue);

        // Recursion to set children for each item.
        return $this->getNestedItems($minParent->toArray(), $mainKey, $nestedKey, $childrenKey);
    }

    /**
     * Get child items of main item by its value.
     *
     * @param mixed $itemValue Value of main item want to get its child items.
     * @param string $parentKey Parent key name of child item.
     *
     * @return Collection
     *
     * @throws Exception
     */
    private function getChildrenOfItemValue(mixed $itemValue, string $parentKey = 'parent_id'): Collection
    {
        if (!is_int($itemValue) && !is_string($itemValue)) {
            throw new Exception('Value of item must be integer or string type');
        }

        return $this->where($parentKey, '=', $itemValue);
    }

    /**
     * Return item list is nested.
     *
     * @param array $items Main item list
     * @param string $mainKey Key of main item.
     * @param string $nestedKey Key which child items will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     *
     * @return array
     *
     * @throws Exception
     */
    private function getNestedItems(array $items, string $mainKey, string $nestedKey, string $childrenKey = 'child_items'): array
    {
        foreach ($items as $key => $item) {
            $childItems = null;

            $itemValue = $item[$mainKey] ?? null;
            if (!$itemValue) {
                continue;
            }

            $childItems = $this->getChildrenOfItemValue($itemValue, $nestedKey)->toArray();

            // If item has children then continue with each child item.
            if (count($childItems)) {
                $childItems = $this->getNestedItems($childItems, $mainKey, $nestedKey);
            }

            $items[$key][$childrenKey] = $childItems;
        }

        return array_values($items);
    }
}
