<?php

namespace Minh164\EloNest;

interface Nestable
{
    /**
     * Get the instance as nested array with multiple dept.
     *
     * @param string $mainKey Key of parent item.
     * @param string $nestedKey Key which child item will base on to be nested.
     * @param string $childrenKey Key name of child items will be returned.
     *
     * @return array
     */
    public function toNestedArray(string $mainKey = 'id', string $nestedKey = 'parent_id', string $childrenKey = 'child_items'): array;
}
