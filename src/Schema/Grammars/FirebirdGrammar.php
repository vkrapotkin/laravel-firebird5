<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Schema\Grammars;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use RuntimeException;

class FirebirdGrammar extends Grammar
{
    protected $modifiers = ['Default', 'Increment', 'Nullable'];

    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    protected $transactions = false;

    public function compileSchemas(): string
    {
        return "select current_user as name, cast(null as varchar(255)) as path, 1 as default from rdb\$database";
    }

    public function compileTableExists($schema, $table): string
    {
        return sprintf(
            'select 1 from rdb$relations where coalesce(rdb$system_flag, 0) = 0 and rdb$view_blr is null and rdb$relation_name = %s',
            $this->quoteString($table)
        );
    }

    public function compileTables($schema): string
    {
        return <<<'SQL'
select trim(r.rdb$relation_name) as name, cast(null as varchar(63)) as schema, trim(r.rdb$relation_name) as schema_qualified_name, cast(null as bigint) as size, trim(r.rdb$description) as comment, cast(null as varchar(63)) as collation, cast(null as varchar(63)) as engine from rdb$relations r where coalesce(r.rdb$system_flag, 0) = 0 and r.rdb$view_blr is null order by trim(r.rdb$relation_name)
SQL;
    }

    public function compileViews($schema): string
    {
        return <<<'SQL'
select trim(r.rdb$relation_name) as name, cast(null as varchar(63)) as schema, trim(r.rdb$relation_name) as schema_qualified_name, trim(r.rdb$view_source) as definition from rdb$relations r where coalesce(r.rdb$system_flag, 0) = 0 and r.rdb$view_blr is not null order by trim(r.rdb$relation_name)
SQL;
    }

    public function compileTypes($schema): string
    {
        return <<<'SQL'
select
    trim(f.rdb$field_name) as name,
    cast(null as varchar(63)) as schema,
    'domain' as type,
    'U' as category,
    0 as implicit
from rdb$fields f
where coalesce(f.rdb$system_flag, 0) = 0
    and f.rdb$field_name not starting with 'RDB$'
order by trim(f.rdb$field_name)
SQL;
    }

    public function compileColumns($schema, $table): string
    {
        return sprintf(<<<'SQL'
select
    trim(rf.rdb$field_name) as name,
    case
        when f.rdb$field_type = 7 and f.rdb$field_sub_type = 0 then 'smallInteger'
        when f.rdb$field_type = 7 and f.rdb$field_sub_type in (1, 2) then 'decimal'
        when f.rdb$field_type = 8 and f.rdb$field_sub_type = 0 then 'integer'
        when f.rdb$field_type = 8 and f.rdb$field_sub_type in (1, 2) then 'decimal'
        when f.rdb$field_type = 10 then 'float'
        when f.rdb$field_type = 12 then 'date'
        when f.rdb$field_type = 13 then 'time'
        when f.rdb$field_type = 14 then 'char'
        when f.rdb$field_type = 16 and f.rdb$field_sub_type = 0 then 'bigInteger'
        when f.rdb$field_type = 16 and f.rdb$field_sub_type in (1, 2) then 'decimal'
        when f.rdb$field_type = 23 then 'boolean'
        when f.rdb$field_type = 24 then 'double'
        when f.rdb$field_type = 25 then 'double'
        when f.rdb$field_type = 26 then 'decimal'
        when f.rdb$field_type = 27 then 'double'
        when f.rdb$field_type = 28 then 'timeTz'
        when f.rdb$field_type = 29 then 'timestampTz'
        when f.rdb$field_type = 35 then 'dateTime'
        when f.rdb$field_type = 37 then 'string'
        when f.rdb$field_type = 261 and f.rdb$field_sub_type = 1 then 'text'
        when f.rdb$field_type = 261 then 'binary'
        else 'string'
    end as type_name,
    case
        when f.rdb$field_type = 14 then 'char(' || f.rdb$character_length || ')'
        when f.rdb$field_type = 37 then 'varchar(' || f.rdb$character_length || ')'
        when f.rdb$field_type in (7, 8, 16, 26) and f.rdb$field_sub_type in (1, 2)
            then 'decimal(' || coalesce(f.rdb$field_precision, 18) || ', ' || abs(f.rdb$field_scale) || ')'
        when f.rdb$field_type = 261 and f.rdb$field_sub_type = 1 then 'blob sub_type text'
        when f.rdb$field_type = 261 then 'blob sub_type binary'
        when f.rdb$field_type = 35 then 'timestamp'
        when f.rdb$field_type = 29 then 'timestamp with time zone'
        when f.rdb$field_type = 28 then 'time with time zone'
        when f.rdb$field_type = 13 then 'time'
        when f.rdb$field_type = 12 then 'date'
        when f.rdb$field_type = 23 then 'boolean'
        when f.rdb$field_type = 27 then 'double precision'
        when f.rdb$field_type = 10 then 'float'
        when f.rdb$field_type = 8 then 'integer'
        when f.rdb$field_type = 7 then 'smallint'
        when f.rdb$field_type = 16 then 'bigint'
        else trim(rf.rdb$field_source)
    end as type,
    iif(coalesce(rf.rdb$null_flag, f.rdb$null_flag, 0) = 0, 1, 0) as nullable,
    trim(coalesce(rf.rdb$default_source, f.rdb$default_source)) as default_source,
    iif(coalesce(rf.rdb$identity_type, 0) > 0, 1, 0) as auto_increment,
    cast(null as varchar(63)) as collation,
    trim(rf.rdb$description) as comment,
    case when f.rdb$computed_source is not null then 'virtual' else null end as generation_type,
    trim(f.rdb$computed_source) as generation_expression
from rdb$relation_fields rf
join rdb$fields f on f.rdb$field_name = rf.rdb$field_source
where rf.rdb$relation_name = %s
order by rf.rdb$field_position
SQL, $this->quoteString($table));
    }

    public function compileIndexes($schema, $table): string
    {
        return sprintf(<<<'SQL'
select
    trim(i.rdb$index_name) as name,
    trim(s.rdb$field_name) as column_name,
    iif(coalesce(rc.rdb$constraint_type, '') = 'PRIMARY KEY', 1, 0) as primary_flag,
    iif(coalesce(rc.rdb$constraint_type, '') in ('PRIMARY KEY', 'UNIQUE') or coalesce(i.rdb$unique_flag, 0) = 1, 1, 0) as unique_flag
from rdb$indices i
join rdb$index_segments s on s.rdb$index_name = i.rdb$index_name
left join rdb$relation_constraints rc on rc.rdb$index_name = i.rdb$index_name
where i.rdb$relation_name = %s and coalesce(i.rdb$system_flag, 0) = 0
order by trim(i.rdb$index_name), s.rdb$field_position
SQL, $this->quoteString($table));
    }

    public function compileForeignKeys($schema, $table): string
    {
        return sprintf(<<<'SQL'
select
    trim(rc.rdb$constraint_name) as name,
    trim(seg.rdb$field_name) as column_name,
    cast(null as varchar(63)) as foreign_schema,
    trim(ref_rc.rdb$relation_name) as foreign_table,
    trim(ref_seg.rdb$field_name) as foreign_column_name,
    trim(ref.rdb$update_rule) as on_update,
    trim(ref.rdb$delete_rule) as on_delete
from rdb$relation_constraints rc
join rdb$ref_constraints ref on ref.rdb$constraint_name = rc.rdb$constraint_name
join rdb$index_segments seg on seg.rdb$index_name = rc.rdb$index_name
join rdb$relation_constraints ref_rc on ref_rc.rdb$constraint_name = ref.rdb$const_name_uq
join rdb$index_segments ref_seg on ref_seg.rdb$index_name = ref_rc.rdb$index_name and ref_seg.rdb$field_position = seg.rdb$field_position
where rc.rdb$relation_name = %s and rc.rdb$constraint_type = 'FOREIGN KEY'
order by trim(rc.rdb$constraint_name), seg.rdb$field_position
SQL, $this->quoteString($table));
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = implode(', ', $this->getColumns($blueprint));

        return 'create table '.$table.' ('.$columns.')';
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        return array_map(
            fn (string $column): string => 'alter table '.$table.' add '.$column,
            $this->getColumns($blueprint)
        );
    }

    public function compileChange(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);
        $column = $command->column;
        $wrapped = $this->wrap($column->name);
        $attributes = $column->getAttributes();

        $statements = [
            'alter table '.$table.' alter column '.$wrapped.' type '.$this->getType($column),
        ];

        if (array_key_exists('default', $attributes)) {
            $statements[] = is_null($column->default)
                ? 'alter table '.$table.' alter column '.$wrapped.' drop default'
                : 'alter table '.$table.' alter column '.$wrapped.' set default '.$this->getDefaultValue($column->default);
        }

        if (array_key_exists('nullable', $attributes)) {
            $statements[] = 'alter table '.$table.' alter column '.$wrapped.($column->nullable ? ' drop not null' : ' set not null');
        }

        return $statements;
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        return array_map(
            fn (string $column): string => 'alter table '.$table.' drop '.$this->wrap($column),
            $command->columns
        );
    }

    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        throw new RuntimeException('Firebird does not support renaming tables with Laravel schema grammar.');
    }

    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' alter column '.$this->wrap($command->from).' to '.$this->wrap($command->to);
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add constraint %s primary key (%s)',
            $this->wrapTable($blueprint),
            $this->wrap($command->index),
            $this->columnize($command->columns)
        );
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add constraint %s unique (%s)',
            $this->wrapTable($blueprint),
            $this->wrap($command->index),
            $this->columnize($command->columns)
        );
    }

    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'create index %s on %s (%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns)
        );
    }

    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop constraint '.$this->wrap($command->index);
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop constraint '.$this->wrap($command->index);
    }

    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop index '.$this->wrap($command->index);
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop constraint '.$this->wrap($command->index);
    }

    public function compileDropAllTables(array $tables): string
    {
        return implode('; ', array_map(
            fn (string $table): string => 'drop table '.$this->wrapTable($table),
            $tables
        ));
    }

    public function compileDropAllViews(array $views): string
    {
        return implode('; ', array_map(
            fn (string $view): string => 'drop view '.$this->wrapTable($view),
            $views
        ));
    }

    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    protected function typeBinary(Fluent $column): string
    {
        return 'blob sub_type binary';
    }

    protected function typeBoolean(Fluent $column): string
    {
        return 'boolean';
    }

    protected function typeChar(Fluent $column): string
    {
        return 'char('.($column->length ?? 255).')';
    }

    protected function typeDate(Fluent $column): string
    {
        return 'date';
    }

    protected function typeDateTime(Fluent $column): string
    {
        return 'timestamp';
    }

    protected function typeDateTimeTz(Fluent $column): string
    {
        return 'timestamp with time zone';
    }

    protected function typeDecimal(Fluent $column): string
    {
        return 'decimal('.($column->total ?? 8).', '.($column->places ?? 2).')';
    }

    protected function typeDouble(Fluent $column): string
    {
        return 'double precision';
    }

    protected function typeEnum(Fluent $column): string
    {
        $length = max(array_map(static fn (string $value): int => strlen($value), $column->allowed ?? [''])) ?: 1;

        return 'varchar('.$length.')';
    }

    protected function typeFloat(Fluent $column): string
    {
        return 'float';
    }

    protected function typeInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeIpAddress(Fluent $column): string
    {
        return 'varchar(45)';
    }

    protected function typeJson(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeJsonb(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeLongText(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeMacAddress(Fluent $column): string
    {
        return 'varchar(17)';
    }

    protected function typeMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeMediumText(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeString(Fluent $column): string
    {
        return 'varchar('.($column->length ?? 255).')';
    }

    protected function typeText(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeTime(Fluent $column): string
    {
        return 'time';
    }

    protected function typeTimeTz(Fluent $column): string
    {
        return 'time with time zone';
    }

    protected function typeTimestamp(Fluent $column): string
    {
        return 'timestamp';
    }

    protected function typeTimestampTz(Fluent $column): string
    {
        return 'timestamp with time zone';
    }

    protected function typeTinyInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeTinyText(Fluent $column): string
    {
        return 'blob sub_type text';
    }

    protected function typeUlid(Fluent $column): string
    {
        return 'char(26)';
    }

    protected function typeUnsignedBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    protected function typeUnsignedInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeUnsignedMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeUnsignedSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeUnsignedTinyInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeUuid(Fluent $column): string
    {
        return 'varchar(36)';
    }

    protected function typeYear(Fluent $column): string
    {
        return 'smallint';
    }

    protected function modifyNullable(Blueprint $blueprint, Fluent $column): string
    {
        return $column->nullable ? '' : ' not null';
    }

    protected function modifyDefault(Blueprint $blueprint, Fluent $column): string
    {
        if (! isset($column->default)) {
            return '';
        }

        return ' default '.$this->getDefaultValue($column->default);
    }

    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): string
    {
        if (! in_array($column->type, $this->serials, true) || ! $column->autoIncrement) {
            return '';
        }

        return $this->hasCommand($blueprint, 'primary') || ($column->change && ! $column->primary)
            ? ' generated by default as identity'
            : ' generated by default as identity primary key';
    }

    protected function getType(Fluent $column): string
    {
        $method = 'type'.ucfirst($column->type);

        if (! method_exists($this, $method)) {
            throw new RuntimeException('Firebird schema grammar does not support column type ['.$column->type.'].');
        }

        return $this->{$method}($column);
    }
}








