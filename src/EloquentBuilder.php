<?php

namespace Llama\Database\Eloquent;

use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EloquentBuilder extends Builder
{

    protected $relationAliases = [];

    /**
     * Get deepest relation instance
     *
     * @param $model
     * @param array $relationName
     * @return null
     */
    public function getRelationInstance($model, array $relationName) {
        $currentRelationName = array_shift($relationName);

        $related = $model->$currentRelationName();
        if (!$related instanceof Relation) {
            return null;
        }

        if (!empty($relationName)) {
            $relation = $related->getRelated();
            return $this->getRelationInstance($relation, $relationName);
        }

        return $related;
    }

    /**
     * Get nested relation
     *
     * @param string $relation
     * @return null
     */
    public function getNestedRelation($relation) {
        if (Str::contains($relation, '.')) {
            $relation = explode('.', $relation);
        } else {
            $relation = [$relation];
        }

        return $this->getRelationInstance($this->model, $relation);
    }

    /**
     * Parse nested relations to flat array
     *
     * @param $relations
     * @return array
     * @internal param string $relation
     */
    public function parseNestedRelations($relations) {
        $results = [];
        foreach ($relations as $relation) {
            $results = $this->parseNestedWith($relation, $results);
        }

        return array_unique(array_keys($results));
    }

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

        // parse nested relations to flat array
        $relations = $this->parseNestedRelations($relations);
        $aliases = $this->parseAliasMap($relations);

        foreach ($relations as $relationName) {
            $relation = $this->getNestedRelation($relationName);
            $relationAlias = $aliases[$relationName];

            $parentName = $this->getParentRelations($relationName);
            $parentAlias = !empty($parentName) ? $aliases[$parentName] : $relation->getParent()->getTable();

            if ($relation instanceof BelongsTo) {
                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    $parentAlias . '.' . $relation->getForeignKey(),
                    '=',
                    $relationAlias . '.' . $relation->getOtherKey(),
                    $type,
                    $where
                );
            } elseif ($relation instanceof BelongsToMany) {
                // pivot
                $pivotAlias = "{$relationAlias}_pivot";

                $this->query->join(
                    "{$relation->getTable()} as {$pivotAlias}",
                    "{$parentAlias}.{$this->getColumn($relation->getQualifiedParentKeyName())}",
                    '=',
                    "{$pivotAlias}.{$this->getColumn($relation->getForeignKey())}",
                    $type,
                    $where
                );

                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    $relationAlias . '.' . $relation->getRelated()->getKeyName(),
                    '=',
                    "{$pivotAlias}.{$this->getColumn($relation->getOtherKey())}",
                    $type,
                    $where
                );
            } else {
                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    "{$parentAlias}.{$this->getColumn($relation->getQualifiedParentKeyName())}",
                    '=',
                    "{$relationAlias}.{$this->getColumn($relation->getForeignKey())}",
                    $type,
                    $where
                );
            }
        }
        $this->query->toSql();
        return $this;
    }

    private function parseAliasMap($relations = []) {
        foreach ($relations as $relationName) {
            $this->relationAliases[$relationName] = str_replace('.', '_rel_', $relationName);
        }

        return $this->relationAliases;
    }

    /**
     * Parse and get only parent relations for nested relations
     * @param $string
     * @return string
     */
    private function getParentRelations($string) {
        $arr = explode('.', $string);
        array_pop($arr);
        return implode('.', $arr);
    }

    /**
     * Parse and get foreign/local keys from db query string
     * @param $string
     * @return mixed
     */
    private function getColumn($string) {
        $arr = explode('.', $string);
        return array_pop($arr);
    }

    /**
     * @return array
     */
    public function getRelationAliases()
    {
        return $this->relationAliases;
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
     * @param $relations
     * @return EloquentBuilder|static
     * @internal param string $relation
     */
    public function crossJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'cross');
    }
}
