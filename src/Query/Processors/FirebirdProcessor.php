<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class FirebirdProcessor extends Processor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int|string|null
    {
        $result = $query->getConnection()->selectFromWriteConnection($sql, $values);

        if ($result === []) {
            return null;
        }

        $record = (array) $result[0];
        $key = $sequence ?: array_key_first($record);

        return $record[$key] ?? null;
    }

    public function processTables($results)
    {
        return array_map(fn ($result): array => [
            'name' => $this->trim((array) $result, 'name'),
            'schema' => $this->nullableTrim((array) $result, 'schema'),
            'schema_qualified_name' => $this->trim((array) $result, 'schema_qualified_name', $this->trim((array) $result, 'name')),
            'size' => $this->nullableInt((array) $result, 'size'),
            'comment' => $this->nullableTrim((array) $result, 'comment'),
            'collation' => $this->nullableTrim((array) $result, 'collation'),
            'engine' => $this->nullableTrim((array) $result, 'engine'),
        ], $results);
    }

    public function processViews($results)
    {
        return array_map(fn ($result): array => [
            'name' => $this->trim((array) $result, 'name'),
            'schema' => $this->nullableTrim((array) $result, 'schema'),
            'schema_qualified_name' => $this->trim((array) $result, 'schema_qualified_name', $this->trim((array) $result, 'name')),
            'definition' => $this->nullableTrim((array) $result, 'definition', ''),
        ], $results);
    }

    public function processColumns($results)
    {
        return array_map(function ($result): array {
            $row = (array) $result;
            $generationExpression = $this->nullableTrim($row, 'generation_expression');

            return [
                'name' => $this->trim($row, 'name'),
                'type_name' => $this->trim($row, 'type_name'),
                'type' => $this->trim($row, 'type'),
                'nullable' => $this->boolValue($row, 'nullable'),
                'default' => $this->normalizeDefault($this->nullableTrim($row, 'default_source')),
                'auto_increment' => $this->boolValue($row, 'auto_increment'),
                'collation' => $this->nullableTrim($row, 'collation'),
                'comment' => $this->nullableTrim($row, 'comment'),
                'generation' => $generationExpression === null ? null : [
                    'type' => $this->trim($row, 'generation_type', 'virtual'),
                    'expression' => $generationExpression,
                ],
            ];
        }, $results);
    }

    public function processIndexes($results)
    {
        $grouped = [];

        foreach ($results as $result) {
            $row = (array) $result;
            $name = $this->trim($row, 'name');

            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'type' => $this->boolValue($row, 'primary_flag') ? 'primary' : 'index',
                    'unique' => $this->boolValue($row, 'unique_flag'),
                    'primary' => $this->boolValue($row, 'primary_flag'),
                ];
            }

            $grouped[$name]['columns'][] = $this->trim($row, 'column_name');
        }

        return array_values($grouped);
    }

    public function processForeignKeys($results)
    {
        $grouped = [];

        foreach ($results as $result) {
            $row = (array) $result;
            $name = $this->trim($row, 'name');

            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'foreign_schema' => $this->nullableTrim($row, 'foreign_schema'),
                    'foreign_table' => $this->trim($row, 'foreign_table'),
                    'foreign_columns' => [],
                    'on_update' => $this->nullableTrim($row, 'on_update'),
                    'on_delete' => $this->nullableTrim($row, 'on_delete'),
                ];
            }

            $grouped[$name]['columns'][] = $this->trim($row, 'column_name');
            $grouped[$name]['foreign_columns'][] = $this->trim($row, 'foreign_column_name');
        }

        return array_values($grouped);
    }

    private function boolValue(array $row, string $key): bool
    {
        return (bool) ($row[$key] ?? false);
    }

    private function nullableInt(array $row, string $key): ?int
    {
        return isset($row[$key]) ? (int) $row[$key] : null;
    }

    private function normalizeDefault(?string $default): ?string
    {
        if ($default === null || $default === '') {
            return null;
        }

        return preg_replace('/^default\s+/i', '', $default) ?: $default;
    }

    private function nullableTrim(array $row, string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return $default;
        }

        $value = trim((string) $row[$key]);

        return $value === '' ? $default : $value;
    }

    private function trim(array $row, string $key, string $default = ''): string
    {
        return $this->nullableTrim($row, $key, $default) ?? $default;
    }
}


