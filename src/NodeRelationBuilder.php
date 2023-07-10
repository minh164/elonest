<?php

namespace Minh164\EloNest;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Minh164\EloNest\Relations\NodeRelation;

/**
 * Query builder with nestable relation.
 */
class NodeRelationBuilder extends ElonestBuilder
{
    /**
     * @var NodeRelation|null
     */
    protected NodeRelation|null $nodeRelationInstance = null;

    /**
     * @param QueryBuilder $query
     * @param NodeRelation|null $nodeRelation
     */
    public function __construct(QueryBuilder $query, ?NodeRelation $nodeRelation = null)
    {
        parent::__construct($query);
        $this->nodeRelationInstance = $nodeRelation;
    }

    /**
     * @return NodeRelation|null
     */
    public function getNodeRelationInstance(): ?NodeRelation
    {
        return $this->nodeRelationInstance;
    }
}
