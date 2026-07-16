<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Tests\Unit;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use Vkrapotkin\LaravelFirebird5\FirebirdConnection;
use Vkrapotkin\LaravelFirebird5\Query\Grammars\FirebirdGrammar as FirebirdQueryGrammar;
use Vkrapotkin\LaravelFirebird5\Schema\Grammars\FirebirdGrammar as FirebirdSchemaGrammar;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FirebirdGrammarTest extends TestCase
{
    public function test_it_compiles_select_with_limit_and_offset_using_offset_fetch(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->select(['id', 'name'])->limit(10)->offset(20);

        self::assertSame(
            'select "id", "name" from "users" offset 20 rows fetch next 10 rows only',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_select_with_limit_only(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->select(['id'])->limit(5);

        self::assertSame(
            'select "id" from "users" fetch first 5 rows only',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_union_queries_without_wrapping_each_branch(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);
        $builder->from('users')->select(['id'])->where('id', 1);

        $union = new Builder($connection, $grammar);
        $union->from('users_archive')->select(['id'])->where('id', 2);

        $builder->unionAll($union)->offset(1)->limit(1);

        self::assertSame(
            'select "id" from "users" where "id" = ? union all select "id" from "users_archive" where "id" = ? offset 1 rows fetch next 1 rows only',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_insert_using_statement(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);
        $builder->from('users_archive');

        self::assertSame(
            'insert into "users_archive" ("id", "name") select "id", "name" from "users"',
            $grammar->compileInsertUsing($builder, ['id', 'name'], 'select "id", "name" from "users"')
        );
    }

    public function test_it_compiles_lock_for_update(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->select(['id'])->where('id', 1)->lockForUpdate();

        self::assertSame(
            'select "id" from "users" where "id" = ? for update with lock',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_pagination_before_lock_for_update(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->select(['id'])->where('id', 1)->lockForUpdate()->limit(1);

        self::assertSame(
            'select "id" from "users" where "id" = ? fetch first 1 rows only for update with lock',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_exists_query_without_select_exists_expression(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->where('id', '69153ec9-953b-4339-b86c-3f62f7426073');

        self::assertSame(
            'select 1 as "exists" from "users" where "id" = ? fetch first 1 rows only',
            $grammar->compileExists($builder)
        );
    }

    public function test_it_keeps_exists_alias_quoted_when_identifier_quoting_is_disabled(): void
    {
        $connection = $this->makeConnection();
        $grammar = (new FirebirdQueryGrammar($connection))->setQuoteIdentifiers(false);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->where('id', '69153ec9-953b-4339-b86c-3f62f7426073');

        self::assertSame(
            'select 1 as "exists" from users where id = ? fetch first 1 rows only',
            $grammar->compileExists($builder)
        );
    }

    public function test_it_compiles_shared_lock(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('users')->select(['id'])->where('id', 1)->sharedLock();

        self::assertSame(
            'select "id" from "users" where "id" = ? with lock',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_compiles_date_based_wheres_with_firebird_syntax(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);

        $builder->from('events')
            ->whereDate('starts_at', '2026-07-15')
            ->whereTime('starts_at', '10:30:00')
            ->whereDay('starts_at', 15)
            ->whereMonth('starts_at', 7)
            ->whereYear('starts_at', 2026);

        self::assertSame(
            'select * from "events" where cast("starts_at" as date) = ? and cast("starts_at" as time) = ? and extract(day from "starts_at") = ? and extract(month from "starts_at") = ? and extract(year from "starts_at") = ?',
            $grammar->compileSelect($builder)
        );
    }

    public function test_it_rejects_insert_or_ignore(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This database engine does not support inserting while ignoring errors.');

        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);
        $builder->from('users');

        $grammar->compileInsertOrIgnore($builder, [['email' => 'a@example.com']]);
    }

    public function test_it_rejects_insert_or_ignore_using(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This database engine does not support inserting while ignoring errors.');

        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);
        $builder->from('users_archive');

        $grammar->compileInsertOrIgnoreUsing($builder, ['id'], 'select "id" from "users"');
    }

    public function test_it_compiles_upsert_for_single_row(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdQueryGrammar($connection);
        $builder = new Builder($connection, $grammar);
        $builder->from('users');

        self::assertSame(
            'update or insert into "users" ("email", "name") values (?, ?) matching ("email")',
            $grammar->compileUpsert($builder, [['email' => 'a@example.com', 'name' => 'Ada']], ['email'], ['name'])
        );
    }

    public function test_it_rejects_table_rename(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Firebird does not support renaming tables');

        $connection = $this->makeConnection();
        $schemaGrammar = new FirebirdSchemaGrammar($connection);
        $blueprint = new Blueprint($connection, 'users');

        $schemaGrammar->compileRename($blueprint, new Fluent(['to' => 'people']));
    }

    public function test_it_compiles_table_exists_query_with_laravel_13_signature(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertSame(
            "select 1 from rdb\$relations where coalesce(rdb\$system_flag, 0) = 0 and rdb\$view_blr is null and rdb\$relation_name = 'USERS'",
            $grammar->compileTableExists(null, 'USERS')
        );
    }

    public function test_it_compiles_metadata_queries_with_uppercase_names_when_identifier_quoting_is_disabled(): void
    {
        $grammar = (new FirebirdSchemaGrammar($this->makeConnection()))->setQuoteIdentifiers(false);

        self::assertSame(
            "select 1 from rdb\$relations where coalesce(rdb\$system_flag, 0) = 0 and rdb\$view_blr is null and rdb\$relation_name = 'MIGRATIONS'",
            $grammar->compileTableExists(null, 'migrations')
        );

        self::assertStringContainsString(
            "where rf.rdb\$relation_name = 'MIGRATIONS'",
            $grammar->compileColumns(null, 'migrations')
        );

        self::assertStringContainsString(
            "where i.rdb\$relation_name = 'MIGRATIONS' and",
            $grammar->compileIndexes(null, 'migrations')
        );
    }

    public function test_firebird_connection_disables_identifier_quoting_by_default(): void
    {
        $connection = $this->makeConnection();

        self::assertSame(
            'select id from users fetch first 1 rows only',
            $connection->table('users')->select('id')->limit(1)->toSql()
        );
    }

    public function test_it_compiles_schemas_query(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertSame(
            "select current_user as name, cast(null as varchar(255)) as path, 1 as default from rdb\$database",
            $grammar->compileSchemas()
        );
    }

    public function test_it_compiles_columns_query_with_inlined_table_name(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertStringContainsString(
            "where rf.rdb\$relation_name = 'USERS'",
            $grammar->compileColumns(null, 'USERS')
        );
    }

    public function test_it_compiles_indexes_query_with_inlined_table_name(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertStringContainsString(
            "where i.rdb\$relation_name = 'USERS' and",
            $grammar->compileIndexes(null, 'USERS')
        );
    }

    public function test_it_compiles_foreign_keys_query_with_inlined_table_name(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertStringContainsString(
            "where rc.rdb\$relation_name = 'POSTS' and rc.rdb\$constraint_type = 'FOREIGN KEY'",
            $grammar->compileForeignKeys(null, 'POSTS')
        );
    }

    public function test_it_compiles_rename_column_statement(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdSchemaGrammar($connection);
        $blueprint = new Blueprint($connection, 'users');
        $command = new Fluent(['from' => 'nickname', 'to' => 'display_name']);

        self::assertSame(
            'alter table "users" alter column "nickname" to "display_name"',
            $grammar->compileRenameColumn($blueprint, $command)
        );
    }

    public function test_it_compiles_column_change_statements(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdSchemaGrammar($connection);
        $blueprint = new Blueprint($connection, 'users');
        $column = new Fluent([
            'name' => 'email',
            'type' => 'string',
            'length' => 320,
            'nullable' => false,
            'default' => 'none@example.com',
        ]);
        $command = new Fluent(['column' => $column]);

        self::assertSame(
            [
                'alter table "users" alter column "email" type varchar(320)',
                'alter table "users" alter column "email" set default \'none@example.com\'',
                'alter table "users" alter column "email" set not null',
            ],
            $grammar->compileChange($blueprint, $command)
        );
    }

    public function test_it_compiles_drop_foreign_statement(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdSchemaGrammar($connection);
        $blueprint = new Blueprint($connection, 'posts');
        $command = new Fluent(['index' => 'posts_user_id_foreign']);

        self::assertSame(
            'alter table "posts" drop constraint "posts_user_id_foreign"',
            $grammar->compileDropForeign($blueprint, $command)
        );
    }


    public function test_it_compiles_drop_primary_statement(): void
    {
        $connection = $this->makeConnection();
        $grammar = new FirebirdSchemaGrammar($connection);
        $blueprint = new Blueprint($connection, 'posts');
        $command = new Fluent(['index' => 'posts_pkey']);

        self::assertSame(
            'alter table "posts" drop constraint "posts_pkey"',
            $grammar->compileDropPrimary($blueprint, $command)
        );
    }

    public function test_it_compiles_drop_all_tables_statement(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertSame(
            'drop table "users"; drop table "posts"',
            $grammar->compileDropAllTables(['users', 'posts'])
        );
    }

    public function test_it_compiles_drop_all_views_statement(): void
    {
        $grammar = new FirebirdSchemaGrammar($this->makeConnection());

        self::assertSame(
            'drop view "active_users"; drop view "recent_posts"',
            $grammar->compileDropAllViews(['active_users', 'recent_posts'])
        );
    }

    private function makeConnection(): FirebirdConnection
    {
        return new class extends FirebirdConnection {
            public function __construct()
            {
                parent::__construct(null, '', '', []);
                $this->useDefaultQueryGrammar();
                $this->useDefaultSchemaGrammar();
            }
        };
    }
}






