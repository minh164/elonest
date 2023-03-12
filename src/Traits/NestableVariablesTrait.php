<?php

namespace Minh164\EloNest\Traits;

trait NestableVariablesTrait
{
    /**
     * @var array
     */
    protected array $nodeRelations = [];

    /**
     * @var string
     */
    protected string $primaryName = 'id';

    /**
     * @var string
     */
    protected string $parentIdKey = 'parent_id';

    /**
     * @var string
     */
    protected string $depthKey = 'depth';

    /**
     * @var string
     */
    protected string $leftKey = 'lft';

    /**
     * @var string
     */
    protected string $rightKey = 'rgt';

    /**
     * Identifier number to determine nodes belong together.
     *
     * @var string
     */
    protected string $originalNumberKey = 'original_number';

    /**
     * Get primary column value.
     *
     * @return int
     */
    public function getPrimaryId(): int
    {
        return $this->id;
    }

    public function getPrimaryName(): string
    {
        return $this->primaryName;
    }

    /**
     * Get left column value.
     *
     * @return int|null
     */
    public function getLeftValue(): ?int
    {
        $leftKey = $this->leftKey;
        return $this->$leftKey;
    }

    /**
     * @param int $value
     */
    public function setLeftValue(int $value): void
    {
        $leftKey = $this->leftKey;
        $this->$leftKey = $value;
    }

    /**
     * @return string
     */
    public function getLeftKey(): string
    {
        return $this->leftKey;
    }

    /**
     * Get right column value.
     *
     * @return int|null
     */
    public function getRightValue(): ?int
    {
        $rightKey = $this->rightKey;
        return $this->$rightKey;
    }

    /**
     * @param int $value
     */
    public function setRightValue(int $value): void
    {
        $rightKey = $this->rightKey;
        $this->$rightKey = $value;
    }

    /**
     * @return string
     */
    public function getRightKey(): string
    {
        return $this->rightKey;
    }

    /**
     * @return int|null
     */
    public function getParentId(): ?int
    {
        $parentKey = $this->parentIdKey;
        return $this->$parentKey;
    }

    /**
     * @param int $value
     */
    public function setParentId(int $value): void
    {
        $parentKey = $this->parentIdKey;
        $this->$parentKey = $value;
    }

    /**
     * @return string
     */
    public function getParentKey(): string
    {
        return $this->parentIdKey;
    }

    /**
     * @return int|null
     */
    public function getDepthValue(): ?int
    {
        $depthKey = $this->depthKey;
        return $this->$depthKey;
    }

    /**
     * @param int $value
     */
    public function setDepthValue(int $value): void
    {
        $depthKey = $this->depthKey;
        $this->$depthKey = $value;
    }

    /**
     * @return string
     */
    public function getDepthKey(): string
    {
        return $this->depthKey;
    }

    /**
     * @return int|null
     */
    public function getOriginalNumberValue(): ?int
    {
        $originalNumberKey = $this->getOriginalNumberKey();
        return $this->$originalNumberKey;
    }

    /**
     * @param int $value
     */
    public function setOriginalNumberValue(int $value): void
    {
        $originalNumberKey = $this->getOriginalNumberKey();
        $this->$originalNumberKey = $value;
    }

    /**
     * @return string
     */
    public function getOriginalNumberKey(): string
    {
        return $this->originalNumberKey;
    }

    /**
     * @return array|null
     */
    public function getNodeRelations(): ?array
    {
        return $this->nodeRelations;
    }

    /**
     * @param array $relations
     */
    public function setNodeRelations(array $relations): void
    {
        foreach ($relations as $key => $value) {
            $this->nodeRelations[$key] = $value;
        }
    }
}
