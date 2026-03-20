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
            'select 1 from rdb$relations where rdb$system_flag = 0 and rdb$view_blr is null and rdb$relation_name = ?',
            $grammar->compileTableExists(null, 'USERS')
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


