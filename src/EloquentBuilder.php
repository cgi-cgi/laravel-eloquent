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

    protected function mapColumns($columns) {
        if (property_exists($this->model, 'mapped')) {
            $mapped = $this->model->mapped;

            if ($columns === ['*']) {
                $columns = $this->getModel()->getVisible();
            }

            foreach ($mapped as $original => $alias) {
                if (($key = array_search($original, $columns)) !== false) {
                    unset($columns[$key]);
                    $columns[$key] = "{$alias} as {$original}";
                }
            }
        }

        return $columns;
    }

    protected function mapColumn($column) {
        if (property_exists($this->getModel(), 'mapped')) {
            $mapped = $this->getModel()->mapped;
            if (str_contains($column, '.')) {
                $column = $this->getColumn($column);
            }

            if (array_key_exists($column, $mapped)) {
                $column = $mapped[$column];
            }
        }

        return $column;
    }

    /**
     * @param array|\Closure|string $column
     * @param null $operator
     * @param null $value
     * @param string $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $column = $this->mapColumn($column);

        if ($column instanceof Closure) {
            $query = $this->model->newQueryWithoutScopes();

            $column($query);

            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
        } else {
            $this->query->where(...func_get_args());
        }

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns = ['*'])
    {
        $columns = $this->mapColumns($columns);
        $builder = $this->applyScopes();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.
        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }

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
            $results = $this->addNestedWiths($relation, $results);
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
                    $relationAlias . '.' . $relation->getOwnerKey(),
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
                    "{$pivotAlias}.{$this->getColumn($relation->getQualifiedForeignKeyName())}",
                    $type,
                    $where
                );

                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    $relationAlias . '.' . $relation->getRelated()->getKeyName(),
                    '=',
                    "{$pivotAlias}.{$this->getColumn($relation->getQualifiedRelatedKeyName())}",
                    $type,
                    $where
                );
            } else {
                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    "{$parentAlias}.{$this->getColumn($relation->getQualifiedParentKeyName())}",
                    '=',
                    "{$relationAlias}.{$this->getColumn($relation->getQualifiedForeignKeyName())}",
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
