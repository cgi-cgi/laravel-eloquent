<?php

namespace Llama\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class EloquentBuilder extends Builder
{
    /**
     * Get deepest relation instance.
     *
     * @param $model
     * @param array $relationName
     *
     * @return null
     */
    public function getRelationInstance($model, array $relationName)
    {
        $currentRelationName = array_shift($relationName);

        $related = $model->$currentRelationName();
        if (!$related instanceof Relation) {
            return;
        }

        if (!empty($relationName)) {
            $relation = $related->getRelated();

            return $this->getRelationInstance($relation, $relationName);
        }

        return $related;
    }

    /**
     * Get nested relation.
     *
     * @param string $relation
     *
     * @return null
     */
    public function getNestedRelation($relation)
    {
        if (Str::contains($relation, '.')) {
            $relation = explode('.', $relation);
        } else {
            $relation = [$relation];
        }

        return $this->getRelationInstance($this->model, $relation);
    }

    /**
     * Parse nested relations to flat array.
     *
     * @param $relations
     *
     * @return array
     *
     * @internal param string $relation
     */
    public function parseNestedRelations($relations)
    {
        $results = [];
        foreach ($relations as $relation) {
            $results = $this->parseNestedWith($relation, $results);
        }

        return array_unique(array_keys($results));
    }

    /**
     * Add a join clause to the query.
     *
     * @param array|string $relations
     * @param string       $type
     * @param bool         $where
     *
     * @return EloquentBuilder
     */
    public function joinRelation($relations, $type = 'inner', $where = false)
    {
        if (is_string($relations)) {
            $relations = [$relations];
        }

        // parse nested relations to flat array
        $relations = $this->parseNestedRelations($relations);

        foreach ($relations as $relation) {
            $relation = $this->getNestedRelation($relation);
            $parent = $relation->getParent();

            if ($relation instanceof BelongsTo) {
                $this->query->join(
                    $relation->getRelated()->getTable(),
                    $parent->getTable().'.'.$relation->getForeignKey(),
                    '=',
                    $relation->getRelated()->getTable().'.'.$relation->getOtherKey(),
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
                    $relation->getRelated()->getTable().'.'.$relation->getRelated()->getKeyName(),
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

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param array|string $relations
     * @param string       $type
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
     * @param array|string $relations
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
     * @param array|string $relations
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
     * @param array|string $relations
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
     * @param array|string $relations
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
     * @param string $relation
     *
     * @return EloquentBuilder|static
     */
    public function crossJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'cross');
    }
}
