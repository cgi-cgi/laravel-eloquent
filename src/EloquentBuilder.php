<?php

namespace Llama\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;

class EloquentBuilder extends Builder
{
    /**
     * The relationships that have been joined.
     *
     * @var array
     */
    protected $joined = [];

    /**
     * Get the hydrated models without eager loading.
     *
     * @param array $columns
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getModels($columns = ['*'])
    {
        $results = $this->query->get($columns);

        $connection = $this->model->getConnectionName();

        // Check for joined relations
        if (!empty($this->joined)) {
            foreach ($results as $key => $result) {
                $relation_values = [];

                foreach ($result as $column => $value) {
                    Arr::set($relation_values, $column, $value);
                }

                foreach ($this->joined as $relationName) {
                    $relation = $this->getRelation($relationName);

                    $relation_values[$relationName] = $relation->getRelated()->newFromBuilder(
                        Arr::pull($relation_values, $relationName),
                        $connection
                    );
                }

                $results[$key] = $relation_values;
            }
        }

        return $this->model->hydrate($results, $connection)->all();
    }

    /**
     * Add a join clause to the query.
     *
     * @param string $relation
     * @param string $type
     * @param bool   $where
     *
     * @return EloquentBuilder
     */
    public function join($relationName, $type = 'inner', $where = false)
    {
        $this->joined[] = $relationName;

        $relation = $this->getRelation($relationName);

        if ($relation instanceof BelongsTo) {
            $this->query->join(
                $relation->getRelated()->getTable(),
                $this->model->getTable().'.'.$relation->getForeignKey(),
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

        $relation_columns = $this->query
            ->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($relation->getRelated()->getTable());

        array_walk($relation_columns, function (&$column) use ($relation, $relationName) {
            $column = $relation->getRelated()->getTable().'.'.$column.' as '.$relationName.'.'.$column;
        });

        $this->query->addSelect(array_merge([$this->model->getTable().'.*'], $relation_columns));

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param string $relation
     * @param string $type
     *
     * @return EloquentBuilder|static
     */
    public function joinWhere($relation, $type = 'inner')
    {
        return $this->join($relation, $type, true);
    }

    /**
     * Add a left join to the query.
     *
     * @param string $relation
     *
     * @return EloquentBuilder|static
     */
    public function leftJoin($relation)
    {
        return $this->join($relation, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param string $relation
     *
     * @return EloquentBuilder|static
     */
    public function leftJoinWhere($relation)
    {
        return $this->joinWhere($relation, 'left');
    }

    /**
     * Add a right join to the query.
     *
     * @param string $relation
     *
     * @return EloquentBuilder|static
     */
    public function rightJoin($relation)
    {
        return $this->join($relation, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     *
     * @param string $relation
     *
     * @return EloquentBuilder|static
     */
    public function rightJoinWhere($relation)
    {
        return $this->joinWhere($relation, 'right');
    }
}
