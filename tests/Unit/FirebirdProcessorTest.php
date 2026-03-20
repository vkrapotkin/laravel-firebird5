<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Tests\Unit;

use Vkrapotkin\LaravelFirebird5\Query\Processors\FirebirdProcessor;
use PHPUnit\Framework\TestCase;

class FirebirdProcessorTest extends TestCase
{
    public function test_it_groups_indexes_from_introspection_rows(): void
    {
        $processor = new FirebirdProcessor();

        $indexes = $processor->processIndexes([
            ['name' => 'USERS_EMAIL_UNQ ', 'column_name' => ' EMAIL ', 'primary_flag' => 0, 'unique_flag' => 1],
            ['name' => 'USERS_NAME_IDX ', 'column_name' => ' NAME ', 'primary_flag' => 0, 'unique_flag' => 0],
        ]);

        self::assertSame([
            [
                'name' => 'USERS_EMAIL_UNQ',
                'columns' => ['EMAIL'],
                'type' => 'index',
                'unique' => true,
                'primary' => false,
            ],
            [
                'name' => 'USERS_NAME_IDX',
                'columns' => ['NAME'],
                'type' => 'index',
                'unique' => false,
                'primary' => false,
            ],
        ], $indexes);
    }

    public function test_it_groups_foreign_keys_from_introspection_rows(): void
    {
        $processor = new FirebirdProcessor();

        $foreignKeys = $processor->processForeignKeys([
            [
                'name' => 'POSTS_USER_ID_FOREIGN ',
                'column_name' => ' USER_ID ',
                'foreign_schema' => null,
                'foreign_table' => ' USERS ',
                'foreign_column_name' => ' ID ',
                'on_update' => ' CASCADE ',
                'on_delete' => ' SET NULL ',
            ],
        ]);

        self::assertSame([
            [
                'name' => 'POSTS_USER_ID_FOREIGN',
                'columns' => ['USER_ID'],
                'foreign_schema' => null,
                'foreign_table' => 'USERS',
                'foreign_columns' => ['ID'],
                'on_update' => 'CASCADE',
                'on_delete' => 'SET NULL',
            ],
        ], $foreignKeys);
    }

    public function test_it_normalizes_columns_from_introspection_rows(): void
    {
        $processor = new FirebirdProcessor();

        $columns = $processor->processColumns([
            [
                'name' => ' EMAIL ',
                'type_name' => 'string',
                'type' => 'varchar(255)',
                'nullable' => 1,
                'default_source' => "DEFAULT 'x@example.com'",
                'auto_increment' => 0,
                'collation' => null,
                'comment' => ' Login email ',
                'generation_type' => null,
                'generation_expression' => null,
            ],
        ]);

        self::assertSame([
            [
                'name' => 'EMAIL',
                'type_name' => 'string',
                'type' => 'varchar(255)',
                'nullable' => true,
                'default' => "'x@example.com'",
                'auto_increment' => false,
                'collation' => null,
                'comment' => 'Login email',
                'generation' => null,
            ],
        ], $columns);
    }
}


