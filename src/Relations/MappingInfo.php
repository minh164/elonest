<?php

namespace Minh164\EloNest\Relations;

use Exception;

class MappingInfo
{
    /**
     * Key name to get children of each parent.
     */
    protected string $keyToMap;

    /**
     * Operation to get children of each parent.
     */
    protected string $operation;

    /**
     * Value to get children of each parent.
     * Includes 3 items of parent node: key_name, operation, number
     * Ex: ["lft", "+", 1].
     */
    protected array $valueToMap = [];

    public function __construct(string $keyToMap, string $operation, array $valueToMap)
    {
        $this->keyToMap = $keyToMap;
        $this->operation = $operation;
        $this->valueToMap = $valueToMap;

        $this->isValidOperation();
        $this->isValidValueToMap();
    }

    /**
     * Determines operation is valid.
     *
     * @return bool
     *
     * @throws Exception
     */
    protected function isValidOperation(): bool
    {
        if (!in_array($this->operation, ['=', '>=', '<=', '>', '<', '><', '!='])) {
            throw new Exception("Operation is invalid, only in: =, >=, <=, ><, !=, >, <");
        }

        return true;
    }

    /**
     * Determines $valueToMap is valid.
     * @return bool
     *
     * @throws Exception
     */
    protected function isValidValueToMap(): bool
    {
        if (!count($this->valueToMap) || count($this->valueToMap) > 3) {
            throw new Exception('$valueToMap is invalid, it must be only includes max 3 items');
        }

        if (empty($this->valueToMap[0]) || !is_string($this->valueToMap[0])) {
            throw new Exception('Item 0 in $valueToMap is invalid, it must be string');
        }

        if (!empty($this->valueToMap[1])) {
            if (!in_array($this->valueToMap[1], ['+', '-', '*', '/'])) {
                throw new Exception("Operation is invalid, only in: +, -, *, /");
            }
        }

        if (!empty($this->valueToMap[2]) && !is_int($this->valueToMap[2])) {
            throw new Exception('Item 2 in $valueToMap is invalid, it must be number');
        }

        return true;
    }

    /**
     * @return string
     */
    public function getKeyToMap(): string
    {
        return $this->keyToMap;
    }

    /**
     * @return string
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @return array
     */
    public function getValueToMap(): array
    {
        return $this->valueToMap;
    }
}
