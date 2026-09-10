<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Illuminate\Support\Arr;
use PDO;
use Vkrapotkin\LaravelFirebird5\Query\FirebirdBuilder as FirebirdQueryBuilder;
use Vkrapotkin\LaravelFirebird5\Query\Grammars\FirebirdGrammar as FirebirdQueryGrammar;
use Vkrapotkin\LaravelFirebird5\Query\Processors\FirebirdProcessor;
use Vkrapotkin\LaravelFirebird5\Schema\FirebirdBuilder as FirebirdSchemaBuilder;
use Vkrapotkin\LaravelFirebird5\Schema\Grammars\FirebirdGrammar as FirebirdSchemaGrammar;

class FirebirdConnection extends Connection
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string, array<string, string>> */
    private array $columnStorageCache = [];

    /** @var array<string, array<string, bool>> */
    private array $scaledNumericColumnCache = [];

    /** @var array<string, list<int>> */
    private array $procedureBinaryUuidParameterCache = [];

    public function query(): BaseQueryBuilder
    {
        return new FirebirdQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        $grammar = new FirebirdQueryGrammar($this);
        $grammar->setTablePrefix($this->getTablePrefix());
        $grammar->setQuoteIdentifiers((bool) ($this->config['quote_identifiers'] ?? false));

        return $grammar;
    }

    protected function getDefaultPostProcessor(): Processor
    {
        return new FirebirdProcessor();
    }

    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        $grammar = new FirebirdSchemaGrammar($this);
        $grammar->setTablePrefix($this->getTablePrefix());
        $grammar->setQuoteIdentifiers((bool) ($this->config['quote_identifiers'] ?? false));

        return $grammar;
    }

    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new FirebirdSchemaBuilder($this);
    }

    public function firebirdPrepareColumnValue(
        mixed $table,
        mixed $column,
        mixed $value,
        array $joins = []
    ): mixed
    {
        if (is_int($value) && $this->firebirdColumnUsesScaledNumeric($table, $column, $joins)) {
            // PDO_Firebird binds PHP ints as an unscaled integer, even when the
            // target NUMERIC/DECIMAL column has a scale. Bind text instead.
            return sprintf('%d.0', $value);
        }

        if ($this->firebirdUuidConversionEnabled()
            && is_string($value)
            && preg_match(self::UUID_PATTERN, $value)
            && $this->firebirdColumnUsesBinaryUuid($table, $column, $joins)
        ) {
            return hex2bin(str_replace('-', '', $value));
        }

        return $value;
    }

    /**
     * Prepare one associative row or a list of rows for INSERT bindings.
     *
     * Raw SQL, including EXECUTE BLOCK, does not contain enough reliable
     * information to associate every positional binding with a table column.
     * Call this method before flattening bindings for such statements.
     *
     * @return list<array<array-key, mixed>>
     */
    public function firebirdPrepareInsertValues(mixed $table, array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (! is_array(array_first($values))) {
            return [$this->firebirdPrepareColumnValues($table, $values)];
        }

        foreach ($values as $key => $value) {
            ksort($value);
            $values[$key] = $this->firebirdPrepareColumnValues($table, $value);
        }

        return $values;
    }

    public function firebirdPrepareQueryBindings(
        mixed $table,
        array $bindings,
        array $wheres,
        array $joins = []
    ): array
    {
        if (! $this->firebirdUuidConversionEnabled()) {
            return Arr::flatten($bindings);
        }

        $prepared = $bindings;
        $prepared['where'] = $this->firebirdPrepareWhereBindings(
            $table,
            $wheres,
            $bindings['where'] ?? [],
            $joins
        );

        return Arr::flatten($prepared);
    }

    public function firebirdPrepareWhereBindings(
        mixed $table,
        array $wheres,
        array $bindings,
        array $joins = []
    ): array
    {
        if (! $this->firebirdUuidConversionEnabled() || $wheres === [] || $this->firebirdWhereBindingsAreAmbiguous($wheres)) {
            return $bindings;
        }

        $index = 0;
        $prepared = [];

        foreach ($wheres as $where) {
            $type = strtolower((string) ($where['type'] ?? ''));

            if (in_array($type, ['basic', 'like', 'bitwise', 'date', 'time', 'day', 'month', 'year', 'nullsafeequals'], true)) {
                if (array_key_exists($index, $bindings)) {
                    $prepared[] = $this->firebirdPrepareColumnValue(
                        $table,
                        $where['column'] ?? null,
                        $bindings[$index],
                        $joins
                    );
                    $index++;
                }
                continue;
            }

            if (in_array($type, ['in', 'notin'], true)) {
                foreach (($where['values'] ?? []) as $value) {
                    if ($value instanceof ExpressionContract) {
                        continue;
                    }

                    if (array_key_exists($index, $bindings)) {
                        $prepared[] = $this->firebirdPrepareColumnValue(
                            $table,
                            $where['column'] ?? null,
                            $bindings[$index],
                            $joins
                        );
                        $index++;
                    }
                }
                continue;
            }

            if ($type === 'between') {
                for ($i = 0; $i < 2; $i++) {
                    if (array_key_exists($index, $bindings)) {
                        $prepared[] = $this->firebirdPrepareColumnValue(
                            $table,
                            $where['column'] ?? null,
                            $bindings[$index],
                            $joins
                        );
                        $index++;
                    }
                }
                continue;
            }

            if ($type === 'nested' && isset($where['query'])) {
                $nestedBindings = array_slice($bindings, $index, count($where['query']->getRawBindings()['where'] ?? []));
                array_push(
                    $prepared,
                    ...$this->firebirdPrepareWhereBindings(
                        $table,
                        $where['query']->wheres ?? [],
                        $nestedBindings,
                        $joins
                    )
                );
                $index += count($nestedBindings);
            }
        }

        if ($index < count($bindings)) {
            array_push($prepared, ...array_slice($bindings, $index));
        }

        return $prepared;
    }

    public function firebirdConvertSelectRows(mixed $table, ?array $columns, array $rows): array
    {
        if (! $this->firebirdUuidConversionEnabled() || $rows === []) {
            return $rows;
        }

        $columnsToConvert = $this->firebirdSelectableBinaryUuidColumns($table, $columns);

        if ($columnsToConvert === []) {
            return $rows;
        }

        foreach ($rows as $row) {
            foreach ($columnsToConvert as $name) {
                $this->firebirdConvertRowColumn($row, $name);
            }
        }

        return $rows;
    }

    public function firebirdConvertColumnValueFromStorage(mixed $table, mixed $column, mixed $value): mixed
    {
        if (! $this->firebirdColumnUsesBinaryUuid($table, $column)) {
            return $value;
        }

        return $this->firebirdBinaryUuidToString($value);
    }

    private function firebirdPrepareColumnValues(mixed $table, array $values): array
    {
        foreach ($values as $column => $value) {
            $values[$column] = $this->firebirdPrepareColumnValue($table, $column, $value);
        }

        return $values;
    }

    public function firebirdColumnUsesBinaryUuid(mixed $table, mixed $column, array $joins = []): bool
    {
        $tableName = $this->firebirdTableNameForColumn($table, $column, $joins);
        $columnName = $this->firebirdNormalizeColumnName($column);

        if ($tableName === null || $columnName === null) {
            return false;
        }

        return ($this->firebirdColumnStorage($tableName)[$columnName] ?? null) === 'binary_uuid';
    }

    public function firebirdColumnUsesScaledNumeric(mixed $table, mixed $column, array $joins = []): bool
    {
        $tableName = $this->firebirdTableNameForColumn($table, $column, $joins);
        $columnName = $this->firebirdNormalizeColumnName($column);

        if ($tableName === null || $columnName === null) {
            return false;
        }

        return $this->firebirdScaledNumericColumns($tableName)[$columnName] ?? false;
    }

    protected function run($query, $bindings, Closure $callback)
    {
        return parent::run(
            $query,
            $this->firebirdPrepareProcedureBindings((string) $query, $bindings),
            $callback
        );
    }

    protected function createTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->reconnectIfMissingConnection();

            $pdo = $this->getPdo();

            if (! $pdo->inTransaction()) {
                $this->executeBeginTransactionStatement();
            }

            return;
        }

        if ($this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint();
        }
    }

    private function firebirdUuidConversionEnabled(): bool
    {
        return (bool) ($this->config['uuid_conversion'] ?? true);
    }

    private function firebirdPrepareProcedureBindings(string $query, array $bindings): array
    {
        if (! $this->firebirdUuidConversionEnabled() || $bindings === []) {
            return $bindings;
        }

        preg_match_all(
            '/(?:\bfrom\b|\bexecute\s+procedure\b)\s+("[^"]+"|[a-z0-9_$]+)\s*\(([^()]*)\)/i',
            $query,
            $calls,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($calls as $call) {
            $procedure = $call[1][0];
            $argumentsText = $call[2][0];
            $argumentsOffset = $call[2][1];
            $binaryParameterIndexes = $this->firebirdProcedureBinaryUuidParameterIndexes($procedure);

            if ($binaryParameterIndexes === []) {
                continue;
            }

            $argumentOffset = 0;
            foreach (explode(',', $argumentsText) as $parameterIndex => $argument) {
                $trimmedArgument = trim($argument);

                if (in_array($parameterIndex, $binaryParameterIndexes, true)
                    && is_string($value = $this->firebirdProcedureArgumentBinding(
                        $query,
                        $bindings,
                        $trimmedArgument,
                        $argumentsOffset + $argumentOffset
                    ))
                    && preg_match(self::UUID_PATTERN, $value)
                ) {
                    $this->firebirdReplaceProcedureArgumentBinding(
                        $query,
                        $bindings,
                        $trimmedArgument,
                        $argumentsOffset + $argumentOffset,
                        hex2bin(str_replace('-', '', $value))
                    );
                }

                $argumentOffset += strlen($argument) + 1;
            }
        }

        return $bindings;
    }

    private function firebirdProcedureArgumentBinding(
        string $query,
        array $bindings,
        string $argument,
        int $argumentOffset
    ): mixed {
        if (preg_match('/^:([a-z_][a-z0-9_]*)$/i', $argument, $match)) {
            return $bindings[$match[1]] ?? $bindings[':'.$match[1]] ?? null;
        }

        if ($argument === '?') {
            $index = substr_count(substr($query, 0, $argumentOffset), '?');

            return $bindings[$index] ?? null;
        }

        return null;
    }

    private function firebirdReplaceProcedureArgumentBinding(
        string $query,
        array &$bindings,
        string $argument,
        int $argumentOffset,
        string $value
    ): void {
        if (preg_match('/^:([a-z_][a-z0-9_]*)$/i', $argument, $match)) {
            if (array_key_exists($match[1], $bindings)) {
                $bindings[$match[1]] = $value;
            } elseif (array_key_exists(':'.$match[1], $bindings)) {
                $bindings[':'.$match[1]] = $value;
            }

            return;
        }

        if ($argument === '?') {
            $index = substr_count(substr($query, 0, $argumentOffset), '?');

            if (array_key_exists($index, $bindings)) {
                $bindings[$index] = $value;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function firebirdProcedureBinaryUuidParameterIndexes(string $procedure): array
    {
        $metadataName = $this->firebirdMetadataIdentifier(
            $this->firebirdNormalizeIdentifier($procedure)
        );

        if (array_key_exists($metadataName, $this->procedureBinaryUuidParameterCache)) {
            return $this->procedureBinaryUuidParameterCache[$metadataName];
        }

        if ($this->getPdo() === null) {
            return $this->procedureBinaryUuidParameterCache[$metadataName] = [];
        }

        $statement = $this->getPdo()->prepare(<<<'SQL'
select
    p.rdb$parameter_number as parameter_number,
    f.rdb$field_type as field_type,
    f.rdb$field_sub_type as field_sub_type,
    f.rdb$field_length as field_length,
    f.rdb$character_length as char_len,
    trim(cs.rdb$character_set_name) as character_set_name
from rdb$procedure_parameters p
join rdb$fields f on f.rdb$field_name = p.rdb$field_source
left join rdb$character_sets cs on cs.rdb$character_set_id = f.rdb$character_set_id
where p.rdb$procedure_name = ?
  and p.rdb$parameter_type = 0
order by p.rdb$parameter_number
SQL);

        $statement->bindValue(1, $metadataName, PDO::PARAM_STR);
        $statement->execute();

        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->firebirdMetadataColumnUsesBinaryUuid($row)) {
                $indexes[] = (int) $this->firebirdRowValue($row, 'parameter_number', 0);
            }
        }

        return $this->procedureBinaryUuidParameterCache[$metadataName] = $indexes;
    }

    private function firebirdWhereBindingsAreAmbiguous(array $wheres): bool
    {
        foreach ($wheres as $where) {
            $type = strtolower((string) ($where['type'] ?? ''));

            if ($type === 'raw' && str_contains((string) ($where['sql'] ?? ''), '?')) {
                return true;
            }

            if ($type === 'nested' && isset($where['query']) && $this->firebirdWhereBindingsAreAmbiguous($where['query']->wheres ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function firebirdColumnStorage(string $table): array
    {
        $metadataName = $this->firebirdMetadataIdentifier($table);

        if (array_key_exists($metadataName, $this->columnStorageCache)) {
            return $this->columnStorageCache[$metadataName];
        }

        if ($this->getPdo() === null) {
            return $this->columnStorageCache[$metadataName] = [];
        }

        $statement = $this->getPdo()->prepare(<<<'SQL'
select
    trim(rf.rdb$field_name) as column_name,
    trim(rf.rdb$field_source) as field_source,
    f.rdb$field_type as field_type,
    f.rdb$field_sub_type as field_sub_type,
    f.rdb$field_length as field_length,
    f.rdb$character_length as char_len,
    trim(cs.rdb$character_set_name) as character_set_name
from rdb$relation_fields rf
join rdb$fields f on f.rdb$field_name = rf.rdb$field_source
left join rdb$character_sets cs on cs.rdb$character_set_id = f.rdb$character_set_id
where rf.rdb$relation_name = ?
SQL);

        $statement->bindValue(1, $metadataName, PDO::PARAM_STR);
        $statement->execute();

        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $column = strtolower(trim((string) $this->firebirdRowValue($row, 'column_name', '')));

            if ($this->firebirdMetadataColumnUsesBinaryUuid($row)) {
                $columns[$column] = 'binary_uuid';
            }
        }

        return $this->columnStorageCache[$metadataName] = $columns;
    }

    /**
     * @return array<string, bool>
     */
    private function firebirdScaledNumericColumns(string $table): array
    {
        $metadataName = $this->firebirdMetadataIdentifier($table);

        if (array_key_exists($metadataName, $this->scaledNumericColumnCache)) {
            return $this->scaledNumericColumnCache[$metadataName];
        }

        if ($this->getPdo() === null) {
            return $this->scaledNumericColumnCache[$metadataName] = [];
        }

        $statement = $this->getPdo()->prepare(<<<'SQL'
select
    trim(rf.rdb$field_name) as column_name,
    f.rdb$field_type as field_type,
    f.rdb$field_sub_type as field_sub_type,
    f.rdb$field_scale as field_scale
from rdb$relation_fields rf
join rdb$fields f on f.rdb$field_name = rf.rdb$field_source
where rf.rdb$relation_name = ?
SQL);
        $statement->bindValue(1, $metadataName, PDO::PARAM_STR);
        $statement->execute();

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $column = strtolower(trim((string) $this->firebirdRowValue($row, 'column_name', '')));
            $fieldType = (int) $this->firebirdRowValue($row, 'field_type', 0);
            $fieldSubtype = (int) $this->firebirdRowValue($row, 'field_sub_type', 0);
            $fieldScale = (int) $this->firebirdRowValue($row, 'field_scale', 0);
            $columns[$column] = in_array($fieldType, [7, 8, 16], true)
                && in_array($fieldSubtype, [1, 2], true)
                && $fieldScale < 0;
        }

        return $this->scaledNumericColumnCache[$metadataName] = $columns;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firebirdMetadataColumnUsesBinaryUuid(array $row): bool
    {
        $fieldType = (int) $this->firebirdRowValue($row, 'field_type', 0);
        $fieldLength = (int) $this->firebirdRowValue($row, 'field_length', 0);
        $characterLength = (int) $this->firebirdRowValue($row, 'char_len', 0);
        $characterSet = strtoupper(trim((string) $this->firebirdRowValue($row, 'character_set_name', '')));

        return in_array($fieldType, [14, 37], true)
            && $fieldLength === 16
            && ($characterLength === 16 || $characterLength === 0)
            && $characterSet === 'OCTETS';
    }

    /**
     * @return list<string>
     */
    private function firebirdSelectableBinaryUuidColumns(mixed $table, ?array $columns): array
    {
        $tableName = $this->firebirdNormalizeTableName($table);

        if ($tableName === null) {
            return [];
        }

        $binaryColumns = array_keys($this->firebirdColumnStorage($tableName));

        if ($columns === null || $columns === ['*']) {
            return $binaryColumns;
        }

        $selected = [];
        $tableQualifiers = $this->firebirdTableQualifiers($table);

        foreach ($columns as $column) {
            if (! is_string($column) || $column === '*') {
                if ($column === '*') {
                    array_push($selected, ...$binaryColumns);
                }
                continue;
            }

            if (preg_match('/^(.+)\.\*$/', trim($column), $matches)
                && in_array($this->firebirdNormalizeQualifier($matches[1]), $tableQualifiers, true)
            ) {
                array_push($selected, ...$binaryColumns);
                continue;
            }

            [$source, $alias] = $this->firebirdSelectColumnNames($column);

            if ($source !== null && in_array($source, $binaryColumns, true)) {
                $selected[] = $alias ?? $source;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * @return list<string>
     */
    private function firebirdTableQualifiers(mixed $table): array
    {
        if (! is_string($table) || trim($table) === '') {
            return [];
        }

        $table = trim($table);
        $source = $table;
        $alias = null;

        if (preg_match('/^(.+?)\s+(?:as\s+)?([^\s]+)$/i', $table, $matches)) {
            $source = $matches[1];
            $alias = $matches[2];
        }

        $qualifiers = [$this->firebirdNormalizeQualifier($source)];

        if ($alias !== null) {
            $qualifiers[] = $this->firebirdNormalizeQualifier($alias);
        }

        return array_values(array_unique(array_filter($qualifiers)));
    }

    private function firebirdNormalizeQualifier(string $identifier): string
    {
        $parts = explode('.', trim($identifier));

        return strtolower($this->firebirdNormalizeIdentifier((string) end($parts)));
    }

    private function firebirdTableNameForColumn(mixed $table, mixed $column, array $joins = []): ?string
    {
        $tableName = $this->firebirdNormalizeTableName($table);
        $qualifier = $this->firebirdColumnQualifier($column);

        if ($qualifier === null) {
            return $tableName;
        }

        if ($tableName !== null && in_array($qualifier, $this->firebirdTableQualifiers($table), true)) {
            return $tableName;
        }

        foreach ($joins as $join) {
            $joinTable = is_object($join) ? ($join->table ?? null) : null;
            $joinedTableName = $this->firebirdNormalizeTableName($joinTable);

            if ($joinedTableName !== null
                && in_array($qualifier, $this->firebirdTableQualifiers($joinTable), true)
            ) {
                return $joinedTableName;
            }
        }

        return $qualifier;
    }

    private function firebirdColumnQualifier(mixed $column): ?string
    {
        if (! is_string($column) || ! str_contains($column, '.')) {
            return null;
        }

        $parts = explode('.', trim($column));
        array_pop($parts);

        if ($parts === []) {
            return null;
        }

        return $this->firebirdNormalizeQualifier((string) end($parts));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function firebirdSelectColumnNames(string $column): array
    {
        $column = trim($column, " \t\n\r\0\x0B\"");

        if (preg_match('/^(.+)\s+as\s+(.+)$/i', $column, $matches)) {
            return [
                $this->firebirdNormalizeColumnName($matches[1]),
                $this->firebirdNormalizeColumnName($matches[2]),
            ];
        }

        return [$this->firebirdNormalizeColumnName($column), null];
    }

    private function firebirdConvertRowColumn(mixed $row, string $column): void
    {
        if (is_object($row)) {
            foreach (array_keys(get_object_vars($row)) as $property) {
                if (strtolower($property) === strtolower($column)) {
                    $row->{$property} = $this->firebirdBinaryUuidToString($row->{$property});
                    return;
                }
            }
        }

        if (is_array($row)) {
            foreach (array_keys($row) as $key) {
                if (strtolower((string) $key) === strtolower($column)) {
                    $row[$key] = $this->firebirdBinaryUuidToString($row[$key]);
                    return;
                }
            }
        }
    }

    private function firebirdBinaryUuidToString(mixed $value): mixed
    {
        if (! is_string($value) || strlen($value) !== 16) {
            return $value;
        }

        $hex = bin2hex($value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.
            substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function firebirdNormalizeTableName(mixed $table): ?string
    {
        if (! is_string($table) || $table === '') {
            return null;
        }

        $table = preg_replace('/\s+as\s+.+$/i', '', trim($table)) ?? $table;
        $table = preg_replace('/\s+.+$/', '', $table) ?? $table;

        return $this->firebirdNormalizeIdentifier($table);
    }

    private function firebirdNormalizeColumnName(mixed $column): ?string
    {
        if (! is_string($column) || $column === '' || $column === '*') {
            return null;
        }

        $column = trim($column);

        if (str_contains($column, '.')) {
            $parts = explode('.', $column);
            $column = (string) end($parts);
        }

        return strtolower($this->firebirdNormalizeIdentifier($column));
    }

    private function firebirdNormalizeIdentifier(string $identifier): string
    {
        return trim($identifier, " \t\n\r\0\x0B\"");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firebirdRowValue(array $row, string $key, mixed $default = null): mixed
    {
        foreach ($row as $rowKey => $value) {
            if (strtolower((string) $rowKey) === strtolower($key)) {
                return $value;
            }
        }

        return $default;
    }

    private function firebirdMetadataIdentifier(string $identifier): string
    {
        return (bool) ($this->config['quote_identifiers'] ?? false) ? $identifier : strtoupper($identifier);
    }
}
