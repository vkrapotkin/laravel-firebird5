<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;
use RuntimeException;

class FirebirdGrammar extends Grammar
{
    public function compileSelect(Builder $query): string
    {
        if (($query->unions || $query->havings) && $query->aggregate) {
            return $this->compileUnionAggregate($query);
        }

        if (isset($query->groupLimit)) {
            return parent::compileSelect($query);
        }

        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        $sql = trim($this->concatenate($this->compileComponents($query)));
        $sql .= $this->compilePaginationClause($query->limit, $query->offset);

        if ($query->unions) {
            $sql .= ' '.$this->compileUnions($query);
        }

        $query->columns = $original;

        return $sql;
    }

    protected function compileColumns(Builder $query, $columns): ?string
    {
        if (! is_null($query->aggregate)) {
            return null;
        }

        $select = $query->distinct ? 'select distinct ' : 'select ';

        return $select.$this->columnize($columns);
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return '';
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return '';
    }

    protected function compileUnions(Builder $query): string
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        $sql .= $this->compilePaginationClause($query->unionLimit ?? null, $query->unionOffset ?? null);

        return ltrim($sql);
    }

    protected function compileUnion(array $union): string
    {
        $conjunction = $union['all'] ? ' union all ' : ' union ';

        return $conjunction.$union['query']->toSql();
    }

    public function compileInsertGetId(Builder $query, $values, $sequence): string
    {
        $sql = $this->compileInsert($query, $values);
        $column = $this->wrap($sequence ?: 'id');

        return $sql.' returning '.$column;
    }

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        if (count($values) !== 1) {
            throw new RuntimeException('Firebird upsert currently supports exactly one row per statement.');
        }

        $row = $values[0];
        $insertColumns = array_keys($row);
        $updateColumns = $update === [] ? [] : array_values($update);
        $allowedUpdateColumns = array_values(array_diff($insertColumns, $uniqueBy));
        sort($updateColumns);
        sort($allowedUpdateColumns);

        if ($updateColumns !== [] && $updateColumns !== $allowedUpdateColumns) {
            throw new RuntimeException('Firebird upsert supports updating all non-matching columns for a single row only.');
        }

        return sprintf(
            'update or insert into %s (%s) values (%s) matching (%s)',
            $this->wrapTable($query->from),
            $this->columnize($insertColumns),
            implode(', ', array_fill(0, count($insertColumns), '?')),
            $this->columnize($uniqueBy)
        );
    }

    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $cleanBindings = Arr::except($bindings, ['select', 'join']);
        $values = Arr::flatten(array_map(fn ($value) => value($value), $values));

        return array_values(array_merge($bindings['join'], $values, Arr::flatten($cleanBindings)));
    }

    public function compileRandom($seed): string
    {
        return 'RAND()';
    }

    protected function compileLock(Builder $query, $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? 'for update with lock' : 'with lock';
    }

    public function compileDelete(Builder $query): string
    {
        if (isset($query->joins)) {
            throw new RuntimeException('Firebird grammar does not support joined delete statements.');
        }

        return parent::compileDelete($query);
    }

    protected function compileDeleteWithoutJoins(Builder $query, $table, $where): string
    {
        $sql = trim("delete from {$table} {$where}");

        if (! empty($query->orders)) {
            $sql .= ' '.$this->compileOrders($query, $query->orders);
        }

        return $sql.$this->compileRowsClause($query->limit, $query->offset);
    }

    public function compileTruncate(Builder $query): array
    {
        return ['delete from '.$this->wrapTable($query->from) => []];
    }

    private function compilePaginationClause(mixed $limit, mixed $offset): string
    {
        if (is_null($limit) && is_null($offset)) {
            return '';
        }

        $clauses = [];

        if (! is_null($offset)) {
            $clauses[] = 'offset '.(int) $offset.' rows';
        }

        if (! is_null($limit)) {
            $clauses[] = 'fetch next '.(int) $limit.' rows only';
        }

        if (is_null($offset) && ! is_null($limit)) {
            $clauses[0] = 'fetch first '.(int) $limit.' rows only';
        }

        return ' '.implode(' ', $clauses);
    }

    private function compileRowsClause(mixed $limit, mixed $offset): string
    {
        if (is_null($limit) && is_null($offset)) {
            return '';
        }

        if (! is_null($limit) && ! is_null($offset)) {
            $start = (int) $offset + 1;
            $finish = (int) $offset + (int) $limit;

            return ' rows '.$start.' to '.$finish;
        }

        if (! is_null($limit)) {
            return ' rows '.(int) $limit;
        }

        return ' rows '.((int) $offset + 1).' to 2147483647';
    }
}
