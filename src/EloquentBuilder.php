<?php

namespace Llama\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EloquentBuilder extends Builder
{
    /**
     * Add a join clause to the query.
     *
     * @param  array|string  $relations
     * @param string $type
     * @param bool   $where
     *
     * @return EloquentBuilder
     */
    public function joinRelation($relations, $type = 'inner', $where = false)
    {
    	if (is_string($relations)) {
    		$relations = [$relations];
    	}
    	
    	foreach ($relations as $relation) {
	        $relation = $this->getRelation($relation);
	
	        if ($relation instanceof BelongsTo) {
	            $this->query->join(
	                $relation->getRelated()->getTable(),
	                $this->model->getTable() . '.' . $relation->getForeignKey(),
	                '=',
	                $relation->getRelated()->getTable() . '.' . $relation->getOtherKey(),
	                $type,
	                $where
	            );
	        } elseif ($relation instanceof BelongsToMany) {
	            $this->query->join(
	                $relation->getTable(),
	                $relation->getQualifiedParentKeyName(),
	                '=',
	                $relation->getForeignKey(),
	                $type,
	                $where
	            );
	
	            $this->query->join(
	                $relation->getRelated()->getTable(),
	                $relation->getRelated()->getTable() . '.' . $relation->getRelated()->getKeyName(),
	                '=',
	                $relation->getOtherKey(),
	                $type,
	                $where
	            );
	        } else {
	            $this->query->join(
	                $relation->getRelated()->getTable(),
	                $relation->getQualifiedParentKeyName(),
	                '=',
	                $relation->getForeignKey(),
	                $type,
	                $where
	            );
	        }
    	}

        $this->query->addSelect($this->model->getTable() . '.*');

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  array|string  $relations
     * @param string $type
     *
     * @return EloquentBuilder|static
     */
    public function joinRelationWhere($relations, $type = 'inner')
    {
        return $this->joinRelation($relations, $type, true);
    }

    /**
     * Add a left join to the query.
     *
     * @param  array|string  $relations
     *
     * @return EloquentBuilder|static
     */
    public function leftjoinRelation($relations)
    {
        return $this->joinRelation($relations, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  array|string  $relations
     *
     * @return EloquentBuilder|static
     */
    public function leftJoinRelationWhere($relations)
    {
        return $this->joinRelationWhere($relations, 'left');
    }

    /**
     * Add a right join to the query.
     *
     * @param  array|string  $relations
     *
     * @return EloquentBuilder|static
     */
    public function rightJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     *
     * @param  array|string  $relations
     *
     * @return EloquentBuilder|static
     */
    public function rightJoinRelationWhere($relations)
    {
        return $this->joinRelationWhere($relations, 'right');
    }

    /**
     * Add a "cross join" clause to the query.
     *
     * @param  string  $relation
     * @return EloquentBuilder|static
     */
    public function crossJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'cross');
    }
}
