<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use UnitEnum;
use Vkrapotkin\LaravelFirebird5\FirebirdConnection;

use function Illuminate\Support\enum_value;

class FirebirdBuilder extends Builder
{
    public function getBindings(): array
    {
        if (! $this->connection instanceof FirebirdConnection) {
            return parent::getBindings();
        }

        return $this->connection->firebirdPrepareQueryBindings($this->from, $this->bindings, $this->wheres);
    }

    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        $values = $this->firebirdPrepareInsertValues($values);

        $this->applyBeforeQueryCallbacks();

        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $this->applyBeforeQueryCallbacks();

        $values = $this->firebirdPrepareColumnValues($values);
        $sql = $this->grammar->compileInsertGetId($this, $values, $sequence);
        $values = $this->cleanBindings($values);

        return $this->processor->processInsertGetId($this, $sql, $values, $sequence);
    }

    public function update(array $values)
    {
        $this->applyBeforeQueryCallbacks();

        $values = (new Collection($values))->map(function ($value, $column) {
            if (! $value instanceof self && ! $value instanceof EloquentBuilder && ! $value instanceof Relation) {
                return ['value' => $value, 'bindings' => match (true) {
                    $value instanceof Collection => $value->all(),
                    $value instanceof UnitEnum => enum_value($value),
                    default => $this->firebirdPrepareColumnValue($column, $value),
                }];
            }

            [$query, $bindings] = $this->parseSub($value);

            return ['value' => new Expression("({$query})"), 'bindings' => fn () => $bindings];
        });

        $sql = $this->grammar->compileUpdate($this, $values->map(fn ($value) => $value['value'])->all());
        $bindings = $this->bindings;
        $bindings['where'] = $this->firebirdPrepareWhereBindings();

        return $this->connection->update($sql, $this->cleanBindings(
            $this->grammar->prepareBindingsForUpdate($bindings, $values->map(fn ($value) => $value['bindings'])->all())
        ));
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        if ($uniqueBy === [] || $uniqueBy === '') {
            throw new \InvalidArgumentException('The unique columns must not be empty.');
        }

        if (empty($values)) {
            return 0;
        } elseif ($update === []) {
            return (int) $this->insert($values);
        }

        $values = $this->firebirdPrepareInsertValues($values);

        if (is_null($update)) {
            $update = array_keys(array_first($values));
        }

        $this->applyBeforeQueryCallbacks();

        $updateBindings = (new Collection($update))
            ->reject(fn ($value, $key) => is_int($key))
            ->map(fn ($value, $key) => $this->firebirdPrepareColumnValue($key, $value))
            ->all();

        $bindings = $this->cleanBindings(array_merge(Arr::flatten($values, 1), $updateBindings));

        return $this->connection->affectingStatement(
            $this->grammar->compileUpsert($this, $values, (array) $uniqueBy, $update),
            $bindings
        );
    }

    public function delete($id = null)
    {
        if (! is_null($id)) {
            $this->where($this->from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        $bindings = $this->bindings;
        $bindings['where'] = $this->firebirdPrepareWhereBindings();

        return $this->connection->delete(
            $this->grammar->compileDelete($this),
            $this->cleanBindings($this->grammar->prepareBindingsForDelete($bindings))
        );
    }

    protected function runSelect()
    {
        $rows = $this->connection->select(
            $this->toSql(),
            $this->getBindings(),
            ! $this->useWritePdo,
            $this->fetchUsing
        );

        if ($this->connection instanceof FirebirdConnection) {
            return $this->connection->firebirdConvertSelectRows($this->from, $this->columns, $rows);
        }

        return $rows;
    }

    private function firebirdPrepareInsertValues(array $values): array
    {
        if (! is_array(array_first($values))) {
            return [$this->firebirdPrepareColumnValues($values)];
        }

        foreach ($values as $key => $value) {
            ksort($value);
            $values[$key] = $this->firebirdPrepareColumnValues($value);
        }

        return $values;
    }

    private function firebirdPrepareColumnValues(array $values): array
    {
        foreach ($values as $column => $value) {
            $values[$column] = $this->firebirdPrepareColumnValue($column, $value);
        }

        return $values;
    }

    private function firebirdPrepareColumnValue(mixed $column, mixed $value): mixed
    {
        if (! $this->connection instanceof FirebirdConnection) {
            return $value;
        }

        return $this->connection->firebirdPrepareColumnValue($this->from, $column, $value);
    }

    private function firebirdPrepareWhereBindings(): array
    {
        if (! $this->connection instanceof FirebirdConnection) {
            return $this->bindings['where'] ?? [];
        }

        return $this->connection->firebirdPrepareWhereBindings($this->from, $this->wheres, $this->bindings['where'] ?? []);
    }
}
