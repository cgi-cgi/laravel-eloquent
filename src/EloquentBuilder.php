<?php

namespace Llama\Database\Eloquent;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use DB;

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
    public function getRelationInstance($model, array $relationName)
    {
        $currentRelationName = array_shift($relationName);

        $relation = $model->$currentRelationName();
        if (!$relation instanceof Relation) {
            return null;
        }

        if (!empty($relationName)) {
            // Can't to detect deep relation for morphed relations...
            if ($relation instanceof MorphTo) {
                return null;
            }

            $relatedModel = $relation->getRelated();
            return $this->getRelationInstance($relatedModel, $relationName);
        }

        return $relation;
    }

    /**
     * Get nested relation
     *
     * @param string $relation
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
     * Parse nested relations to flat array
     *
     * @param $relations
     * @return array
     * @internal param string $relation
     */
    public function parseNestedRelations($relations)
    {
        $results = [];
        foreach ($relations as $relation) {
            $results = $this->addNestedWiths($relation, $results);
        }

        return array_unique(array_keys($results));
    }

    /**
     * Add a join clause to the query.
     *
     * @param  array|string $relations
     * @param string $type
     * @param bool $where
     *
     * @return EloquentBuilder
     * @throws \Exception
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
            if (!$relation) continue;

            $relationAlias = $aliases[$relationName];

            $parentName = $this->getParentRelations($relationName);
            $parentAlias = !empty($parentName) ? $aliases[$parentName] : $relation->getParent()->getTable();

            $relationWithoutConstraints = null;
            // retrieve relation query without any constraints -
            // avoid automatic queries based on foreign/related keys,
            // because we are trying to do "join" via "sub-query" (as simplest way, but not the fastest?)
            // it needed for relations, where additional conditions are used - like where(), orderBy(), etc
            $relation::noConstraints(function() use (&$relationWithoutConstraints, $relationName) {
                $relationWithoutConstraints = $this->getNestedRelation($relationName);
            });

            if ($relation instanceof MorphTo) {
                $join = $this->getJoinMorphOne($relation, $relationAlias);

                $this->query->joinSub(
                    $join['table'],
                    $relationAlias,
                    function($joinQuery) use ($join, $type, $parentAlias, $relationAlias, $relation) {
                        $joinQuery->on($join['first'], '=', "{$parentAlias}.{$relation->getForeignKeyName()}");
                        $joinQuery->on("{$parentAlias}.{$relation->getMorphType()}", '=', "{$relationAlias}.sub_{$relation->getMorphType()}");
                    },
                    null, null, $type
                );
            } elseif ($relation instanceof BelongsTo) {
                $this->query->joinSub(
                    $relationWithoutConstraints->getQuery(),
                    $relationAlias,
                    $parentAlias . '.' . $relation->getForeignKeyName(),
                    '=',
                    $relationAlias . '.' . $relation->getOwnerKeyName(),
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
                    "{$pivotAlias}.{$this->getColumn($relation->getQualifiedForeignPivotKeyName())}",
                    $type,
                    $where
                );

                $this->query->join(
                    "{$relation->getRelated()->getTable()} as {$relationAlias}",
                    $relationAlias . '.' . $relation->getRelated()->getKeyName(),
                    '=',
                    "{$pivotAlias}.{$this->getColumn($relation->getQualifiedRelatedPivotKeyName())}",
                    $type,
                    $where
                );
            } elseif ($relation instanceof HasOneOrMany) {
                // hasOne or hasMany

                $this->query->joinSub(
                    $relationWithoutConstraints->getQuery(),
                    $relationAlias,
                    function($joinQuery) use ($type, $parentAlias, $relationAlias, $relation) {
                        $joinQuery->on(
                            "{$parentAlias}.{$this->getColumn($relation->getQualifiedParentKeyName())}",
                            '=',
                            "{$relationAlias}.{$this->getColumn($relation->getQualifiedForeignKeyName())}"
                        );

                        // additional extra join function for support "has one" for morphTo relations
                        if (property_exists($relation, 'joinExtra')) {
                            call_user_func($relation->joinExtra, $joinQuery, $parentAlias, $relationAlias);
                        }
                    },
                    $type,
                    $where
                );
            }
        }

        return $this;
    }

    /**
     * @param MorphTo $relation
     * @return array
     * @throws \Exception
     */
    private function getMorphToModels(MorphTo $relation): array
    {
        $relatedModel = $relation->getRelated();

        // doesnt support if there is not any morph map
        if (!$relatedModel::MORPH_MAP) {
            throw new \Exception(class_basename($relatedModel) . ' doesn\' have morph map for join');
        }
        if (!$relatedModel::MORPH_MAP[$relation->getRelation()]) {
            throw new \Exception(class_basename($relatedModel) . ' doesn\' have morph map for `' . $relation->getRelation() . '``');
        }

        $models = [];
        $relationMap = $relatedModel::MORPH_MAP[$relation->getRelation()];
        foreach ($relationMap as $morphName) {
            $morphToModel = $relation->createModelByType($morphName);
            $models[] = $morphToModel;
        }

        return $models;
    }

    /**
     * @param MorphTo $relation
     * @param string $relationAlias
     * @return array
     * @throws \Exception
     */
    private function getJoinMorphOne(MorphTo $relation, string $relationAlias): array
    {
        $primaryKey = null;
        $queries = [];
        $models = $this->getMorphToModels($relation);
        $countRelations = count($models);
        $fields = [];
        foreach ($models as $morphToModel) {
            $queries[$morphToModel->getMorphClass()] = DB::table($morphToModel->getTable())
                ->addSelect(
                    DB::raw("'{$morphToModel->getMorphClass()}' as `sub_{$relation->getMorphType()}`")
                );

            $primaryKey = $morphToModel->getKeyName();
            $fields = array_merge($fields, $morphToModel->getVisible());
            // exclude getters and relations
            $fields = array_filter($fields, function ($attribute) use ($morphToModel) {
                return !(
                    property_exists($morphToModel, $attribute) ||
                    method_exists($morphToModel, $attribute) ||
                    method_exists($morphToModel, Str::camel("get{$attribute}Attribute"))
                );
            });
        }

        // find the common attributes for correct union
        $commonFields = array_count_values($fields);
        $commonFields = array_filter($commonFields, function ($count) use ($countRelations) {
            return $count === $countRelations;
        });
        $commonFields = array_keys($commonFields);

        // make union query based on common attributes
        $union = null;
        foreach ($queries as $query) {
            $query->addSelect($commonFields);
            if ($union) $union->union($query);
            else $union = $query;
        }

        // join to temp (sub) table with unions
        // $raw = DB::raw('(' . $union->toSql()) . ") as `{$relationAlias}`";
        return [
            'table' => $union,
            'first' => $relationAlias . '.' . $primaryKey
        ];
    }

    private function parseAliasMap($relations = [])
    {
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
    private function getParentRelations($string)
    {
        $arr = explode('.', $string);
        array_pop($arr);
        return implode('.', $arr);
    }

    /**
     * Parse and get foreign/local keys from db query string
     * @param $string
     * @return mixed
     */
    private function getColumn($string)
    {
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
     * @param  array|string $relations
     * @param string $type
     *
     * @return EloquentBuilder|static
     * @throws \Exception
     */
    public function joinRelationWhere($relations, $type = 'inner')
    {
        return $this->joinRelation($relations, $type, true);
    }

    /**
     * Add a left join to the query.
     *
     * @param  array|string $relations
     *
     * @return EloquentBuilder|static
     * @throws \Exception
     */
    public function leftjoinRelation($relations)
    {
        return $this->joinRelation($relations, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  array|string $relations
     *
     * @return EloquentBuilder|static
     * @throws \Exception
     */
    public function leftJoinRelationWhere($relations)
    {
        return $this->joinRelationWhere($relations, 'left');
    }

    /**
     * Add a right join to the query.
     *
     * @param  array|string $relations
     *
     * @return EloquentBuilder|static
     * @throws \Exception
     */
    public function rightJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     *
     * @param  array|string $relations
     *
     * @return EloquentBuilder|static
     * @throws \Exception
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
     * @throws \Exception
     * @internal param string $relation
     */
    public function crossJoinRelation($relations)
    {
        return $this->joinRelation($relations, 'cross');
    }
}
