<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Tests\Integration;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vkrapotkin\LaravelFirebird5\Tests\TestCase;

class FirebirdIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_firebird')) {
            self::markTestSkipped('pdo_firebird extension is not loaded.');
        }

        $this->recreateDatabase();
        DB::purge('firebird');
    }

    protected function tearDown(): void
    {
        DB::disconnect('firebird');
        DB::purge('firebird');

        parent::tearDown();
    }

    public function test_it_runs_crud_upsert_pagination_and_truncate_against_real_firebird(): void
    {
        $this->migrateBase();

        $connection = DB::connection('firebird');
        $widgets = static fn () => $connection->table('widgets');

        self::assertTrue(Schema::connection('firebird')->hasTable('migrations'));
        self::assertTrue(Schema::connection('firebird')->hasTable('widgets'));

        $firstId = $widgets()->insertGetId([
            'name' => 'alpha',
            'created_at' => '2026-03-20 12:00:00',
            'updated_at' => '2026-03-20 12:00:00',
        ]);

        $secondId = $widgets()->insertGetId([
            'name' => 'beta',
            'created_at' => '2026-03-20 12:05:00',
            'updated_at' => '2026-03-20 12:05:00',
        ]);

        self::assertNotNull($firstId);
        self::assertNotNull($secondId);
        self::assertSame(2, $widgets()->count());
        self::assertSame(2, $widgets()->whereDate('created_at', '2026-03-20')->count());
        self::assertSame(1, $widgets()->whereTime('created_at', '12:05:00')->count());
        self::assertSame(2, $widgets()->whereDay('created_at', 20)->count());
        self::assertSame(2, $widgets()->whereMonth('created_at', 3)->count());
        self::assertSame(2, $widgets()->whereYear('created_at', 2026)->count());

        $names = $widgets()->orderBy('id')->pluck('name')->all();
        self::assertSame(['alpha', 'beta'], $names);

        $pagedNames = $widgets()->orderBy('id')->offset(1)->limit(1)->pluck('name')->all();
        self::assertSame(['beta'], $pagedNames);

        $updated = $widgets()->where('id', $firstId)->update(['name' => 'alpha-updated']);
        self::assertSame(1, $updated);
        self::assertSame('alpha-updated', $widgets()->where('id', $firstId)->value('name'));

        $widgets()->upsert([
            'id' => $firstId,
            'name' => 'alpha-upserted',
            'created_at' => '2026-03-20 12:00:00',
            'updated_at' => '2026-03-20 12:10:00',
        ], ['id'], ['name', 'created_at', 'updated_at']);

        self::assertSame('alpha-upserted', $widgets()->where('id', $firstId)->value('name'));

        $widgets()->upsert([
            'id' => 999,
            'name' => 'gamma',
            'created_at' => '2026-03-20 12:15:00',
            'updated_at' => '2026-03-20 12:15:00',
        ], ['id'], ['name', 'created_at', 'updated_at']);

        self::assertSame(3, $widgets()->count());
        self::assertSame('gamma', $widgets()->where('id', 999)->value('name'));

        $deleted = $widgets()->where('id', $secondId)->delete();
        self::assertSame(1, $deleted);
        self::assertSame(2, $widgets()->count());

        $widgets()->truncate();
        self::assertSame(0, $widgets()->count());

        $this->rollbackBase();
        self::assertFalse(Schema::connection('firebird')->hasTable('widgets'));
    }

    public function test_it_reads_schema_metadata_and_drops_views_and_tables(): void
    {
        $this->migrateBase();

        $schema = Schema::connection('firebird');
        $connection = DB::connection('firebird');

        $schema->table('widgets', function (Blueprint $table): void {
            $table->unique('name', 'widgets_name_unique');
            $table->index('created_at', 'widgets_created_at_idx');
        });

        $schema->create('widget_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('widget_id');
            $table->string('message', 120)->nullable();
            $table->foreign('widget_id', 'widget_logs_widget_id_foreign')->references('id')->on('widgets');
        });

        $connection->statement('create view active_widgets as select id, name from widgets');
        $connection->statement('create view widget_names as select name from widgets');

        $columns = $schema->getColumns('widgets');
        $columnNames = array_map(static fn (array $column): string => strtolower($column['name']), $columns);
        sort($columnNames);

        self::assertSame(['created_at', 'id', 'name', 'updated_at'], $columnNames);
        self::assertTrue($schema->hasColumns('widgets', ['id', 'name', 'created_at', 'updated_at']));

        $idColumn = collect($columns)->first(fn (array $column): bool => strtolower($column['name']) === 'id');
        self::assertNotNull($idColumn);
        self::assertTrue($idColumn['auto_increment']);

        $indexes = $schema->getIndexes('widgets');
        $indexNames = array_map(static fn (array $index): string => strtolower($index['name']), $indexes);
        sort($indexNames);

        self::assertContains('widgets_created_at_idx', $indexNames);
        self::assertContains('widgets_name_unique', $indexNames);
        self::assertTrue(collect($indexes)->contains(fn (array $index): bool => $index['primary']));

        $foreignKeys = $schema->getForeignKeys('widget_logs');
        self::assertCount(1, $foreignKeys);
        self::assertSame('widget_logs_widget_id_foreign', strtolower($foreignKeys[0]['name']));
        self::assertSame(['widget_id'], array_map('strtolower', $foreignKeys[0]['columns']));
        self::assertSame('widgets', strtolower($foreignKeys[0]['foreign_table']));
        self::assertSame(['id'], array_map('strtolower', $foreignKeys[0]['foreign_columns']));

        $tables = $schema->getTables();
        $tableNames = array_map(static fn (array $table): string => strtolower($table['name']), $tables);
        self::assertContains('widgets', $tableNames);
        self::assertContains('widget_logs', $tableNames);
        self::assertContains('migrations', $tableNames);

        $views = $schema->getViews();
        $viewNames = array_map(static fn (array $view): string => strtolower($view['name']), $views);
        sort($viewNames);

        self::assertContains('active_widgets', $viewNames);
        self::assertContains('widget_names', $viewNames);

        $schema->dropAllViews();

        self::assertFalse($schema->hasView('active_widgets'));
        self::assertFalse($schema->hasView('widget_names'));

        $schema->dropAllTables();

        self::assertFalse($schema->hasTable('widgets'));
        self::assertFalse($schema->hasTable('widget_logs'));
        self::assertFalse($schema->hasTable('migrations'));
    }

    public function test_it_supports_schema_changes_and_index_drops(): void
    {
        $schema = Schema::connection('firebird');
        $connection = DB::connection('firebird');

        $schema->create('ddl_playground', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->string('nickname', 20)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        self::assertTrue($schema->hasTable('ddl_playground'));
        self::assertTrue($schema->hasColumns('ddl_playground', ['id', 'name', 'nickname', 'created_at']));

        $schema->table('ddl_playground', function (Blueprint $table): void {
            $table->renameColumn('nickname', 'alias');
        });

        self::assertTrue($schema->hasColumn('ddl_playground', 'alias'));
        self::assertFalse($schema->hasColumn('ddl_playground', 'nickname'));

        $schema->table('ddl_playground', function (Blueprint $table): void {
            $table->string('name', 80)->default('unknown')->change();
        });

        $columns = $schema->getColumns('ddl_playground');
        $nameColumn = collect($columns)->first(fn (array $column): bool => strtolower($column['name']) === 'name');
        self::assertNotNull($nameColumn);
        self::assertSame('varchar(80)', strtolower($nameColumn['type']));
        self::assertSame("'unknown'", strtolower((string) $nameColumn['default']));

        $schema->table('ddl_playground', function (Blueprint $table): void {
            $table->unique('name', 'ddl_playground_name_unique');
            $table->index('created_at', 'ddl_playground_created_at_idx');
        });

        $indexNames = array_map(
            static fn (array $index): string => strtolower($index['name']),
            $schema->getIndexes('ddl_playground')
        );

        self::assertContains('ddl_playground_created_at_idx', $indexNames);
        self::assertContains('ddl_playground_name_unique', $indexNames);

        $schema->table('ddl_playground', function (Blueprint $table): void {
            $table->dropIndex('ddl_playground_created_at_idx');
            $table->dropUnique('ddl_playground_name_unique');
        });

        $indexNames = array_map(
            static fn (array $index): string => strtolower($index['name']),
            $schema->getIndexes('ddl_playground')
        );

        self::assertNotContains('ddl_playground_created_at_idx', $indexNames);
        self::assertNotContains('ddl_playground_name_unique', $indexNames);

        $schema->table('ddl_playground', function (Blueprint $table): void {
            $table->dropColumn('alias');
        });

        self::assertFalse($schema->hasColumn('ddl_playground', 'alias'));
        self::assertTrue($schema->hasColumns('ddl_playground', ['id', 'name', 'created_at']));

        $connection->table('ddl_playground')->insert([
            'name' => 'delta',
            'created_at' => '2026-03-20 13:00:00',
        ]);

        self::assertSame(1, $connection->table('ddl_playground')->count());

        $schema->drop('ddl_playground');
        self::assertFalse($schema->hasTable('ddl_playground'));
    }

    public function test_it_supports_primary_key_drops_and_reverting_column_defaults(): void
    {
        $schema = Schema::connection('firebird');

        $schema->create('primary_playground', function (Blueprint $table): void {
            $table->integer('code');
            $table->string('label', 30)->nullable();
        });

        $schema->table('primary_playground', function (Blueprint $table): void {
            $table->primary('code', 'primary_playground_pkey');
        });

        self::assertTrue(collect($schema->getIndexes('primary_playground'))->contains(
            fn (array $index): bool => strtolower($index['name']) === 'primary_playground_pkey' && $index['primary']
        ));

        $schema->table('primary_playground', function (Blueprint $table): void {
            $table->string('label', 50)->nullable(false)->default('seed')->change();
        });

        $labelColumn = collect($schema->getColumns('primary_playground'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'label');

        self::assertNotNull($labelColumn);
        self::assertSame('varchar(50)', strtolower($labelColumn['type']));
        self::assertSame("'seed'", strtolower((string) $labelColumn['default']));
        self::assertFalse((bool) $labelColumn['nullable']);

        $schema->table('primary_playground', function (Blueprint $table): void {
            $table->string('label', 50)->nullable()->default(null)->change();
        });

        $labelColumn = collect($schema->getColumns('primary_playground'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'label');

        self::assertNotNull($labelColumn);
        self::assertSame('varchar(50)', strtolower($labelColumn['type']));
        self::assertTrue((bool) $labelColumn['nullable']);
        self::assertTrue($labelColumn['default'] === null || trim((string) $labelColumn['default']) === '');

        $schema->table('primary_playground', function (Blueprint $table): void {
            $table->dropPrimary('primary_playground_pkey');
        });

        self::assertFalse(collect($schema->getIndexes('primary_playground'))->contains(
            fn (array $index): bool => $index['primary']
        ));

        $schema->drop('primary_playground');
        self::assertFalse($schema->hasTable('primary_playground'));
    }

    public function test_it_supports_foreign_key_drops_column_changes_and_domain_listing(): void
    {
        $schema = Schema::connection('firebird');
        $connection = DB::connection('firebird');

        $connection->statement("create domain \"widget_status\" as varchar(20) default 'draft' not null");

        $schema->create('rename_source', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 40)->nullable();
        });

        $schema->create('rename_children', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rename_source_id');
            $table->foreign('rename_source_id', 'rename_children_source_fk')->references('id')->on('rename_source');
        });

        $foreignKeys = $schema->getForeignKeys('rename_children');
        self::assertCount(1, $foreignKeys);
        self::assertSame('rename_children_source_fk', strtolower($foreignKeys[0]['name']));

        $schema->table('rename_children', function (Blueprint $table): void {
            $table->dropForeign('rename_children_source_fk');
        });

        self::assertSame([], $schema->getForeignKeys('rename_children'));

        $schema->table('rename_source', function (Blueprint $table): void {
            $table->string('name', 60)->nullable(false)->default('renamed')->change();
        });

        $nameColumn = collect($schema->getColumns('rename_source'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'name');

        self::assertNotNull($nameColumn);
        self::assertSame('varchar(60)', strtolower($nameColumn['type']));
        self::assertSame("'renamed'", strtolower((string) $nameColumn['default']));
        self::assertFalse((bool) $nameColumn['nullable']);

        $schema->table('rename_source', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_id')->nullable();
        });

        $schema->table('rename_source', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_id')->nullable(false)->default(77)->change();
        });

        $externalIdColumn = collect($schema->getColumns('rename_source'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'external_id');

        self::assertNotNull($externalIdColumn);
        self::assertSame('bigint', strtolower($externalIdColumn['type']));
        self::assertSame("'77'", trim((string) $externalIdColumn['default']));
        self::assertFalse((bool) $externalIdColumn['nullable']);

        $types = $schema->getTypes();
        $typeNames = array_map(
            static fn (object|array $type): string => strtolower((string) (is_array($type) ? $type['name'] : $type->name)),
            $types
        );

        self::assertContains('widget_status', $typeNames);

        $schema->drop('rename_children');
        $schema->drop('rename_source');
        $connection->statement('drop domain "widget_status"');

        self::assertFalse($schema->hasTable('rename_children'));
        self::assertFalse($schema->hasTable('rename_source'));
    }

    public function test_it_supports_insert_using_union_pagination_and_lock_for_update(): void
    {
        $this->migrateBase();

        $schema = Schema::connection('firebird');
        $connection = DB::connection('firebird');
        $widgets = $connection->table('widgets');

        $firstId = $widgets->insertGetId([
            'name' => 'alpha',
            'created_at' => '2026-03-20 14:00:00',
            'updated_at' => '2026-03-20 14:00:00',
        ]);

        $secondId = $widgets->insertGetId([
            'name' => 'beta',
            'created_at' => '2026-03-20 14:05:00',
            'updated_at' => '2026-03-20 14:05:00',
        ]);

        $schema->create('widget_copies', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->nullable();
            $table->string('name', 255)->nullable();
        });

        $inserted = $connection->table('widget_copies')->insertUsing(
            ['id', 'name'],
            $connection->table('widgets')->select('id', 'name')->where('id', $firstId)
        );

        self::assertSame(1, $inserted);
        self::assertSame('alpha', $connection->table('widget_copies')->where('id', $firstId)->value('name'));

        $unionRows = $connection->table('widgets')
            ->select('id', 'name')
            ->where('id', $firstId)
            ->unionAll(
                $connection->table('widgets')->select('id', 'name')->where('id', $secondId)
            )
            ->offset(1)
            ->limit(1)
            ->get();

        self::assertCount(1, $unionRows);
        self::assertSame($secondId, (int) $unionRows[0]->id);
        self::assertSame('beta', $unionRows[0]->name);

        $lockedIds = $connection->table('widgets')
            ->select('id')
            ->where('id', $firstId)
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertSame([(int) $firstId], $lockedIds);

        $sharedLockedIds = $connection->table('widgets')
            ->select('id')
            ->where('id', $secondId)
            ->sharedLock()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertSame([(int) $secondId], $sharedLockedIds);

        $schema->drop('widget_copies');
        self::assertFalse($schema->hasTable('widget_copies'));
    }

    public function test_it_converts_uuid_values_using_firebird_column_metadata(): void
    {
        $connection = DB::connection('firebird');

        $connection->statement('create domain guid_binary as char(16) character set octets');
        $connection->statement('create table uuid_playground (id guid_binary not null primary key, parent_id guid_binary, text_uuid varchar(36))');

        $id = '018f1f0b-4f8f-7a1a-8f74-69d2b8190c11';
        $parentId = '018f1f0b-4f8f-7a1a-8f74-69d2b8190c12';
        $textUuid = '018f1f0b-4f8f-7a1a-8f74-69d2b8190c13';

        $connection->table('uuid_playground')->insert([
            'id' => $id,
            'parent_id' => $parentId,
            'text_uuid' => $textUuid,
        ]);

        $stored = $connection->getPdo()
            ->query('select id, parent_id, text_uuid from uuid_playground')
            ->fetch(\PDO::FETCH_ASSOC);
        $stored = array_change_key_case($stored, CASE_LOWER);

        self::assertSame(16, strlen($stored['id']));
        self::assertSame(16, strlen($stored['parent_id']));
        self::assertSame($textUuid, trim($stored['text_uuid']));

        $row = $connection->table('uuid_playground')->where('id', $id)->first();

        self::assertSame($id, $row->ID ?? $row->id);
        self::assertSame($parentId, $row->PARENT_ID ?? $row->parent_id);
        self::assertSame($textUuid, trim($row->TEXT_UUID ?? $row->text_uuid));

        $replacementParentId = '018f1f0b-4f8f-7a1a-8f74-69d2b8190c14';

        self::assertSame(1, $connection->table('uuid_playground')->where('text_uuid', $textUuid)->update([
            'parent_id' => $replacementParentId,
        ]));

        self::assertSame(
            $replacementParentId,
            $connection->table('uuid_playground')->whereIn('id', [$id])->value('parent_id')
        );

        self::assertSame(1, $connection->table('uuid_playground')->where('id', $id)->delete());

        $connection->statement('drop table uuid_playground');
        $connection->statement('drop domain guid_binary');
    }

    public function test_it_adopts_the_active_firebird_transaction_and_supports_nested_savepoints(): void
    {
        $this->migrateBase();

        $connection = DB::connection('firebird');
        $widgets = $connection->table('widgets');

        self::assertSame(0, $connection->transactionLevel());

        $connection->beginTransaction();

        self::assertSame(1, $connection->transactionLevel());

        $insertedId = $widgets->insertGetId([
            'name' => 'tx-commit',
            'created_at' => '2026-03-20 15:00:00',
            'updated_at' => '2026-03-20 15:00:00',
        ]);

        $connection->commit();

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(
            'tx-commit',
            $connection->table('widgets')->where('id', $insertedId)->value('name')
        );

        $connection->beginTransaction();

        $rolledBackId = $widgets->insertGetId([
            'name' => 'tx-rollback',
            'created_at' => '2026-03-20 15:05:00',
            'updated_at' => '2026-03-20 15:05:00',
        ]);

        $connection->rollBack();

        self::assertSame(0, $connection->transactionLevel());
        self::assertNull($connection->table('widgets')->where('id', $rolledBackId)->value('name'));

        $connection->beginTransaction();

        $outerId = $widgets->insertGetId([
            'name' => 'tx-outer',
            'created_at' => '2026-03-20 15:10:00',
            'updated_at' => '2026-03-20 15:10:00',
        ]);

        $connection->beginTransaction();
        self::assertSame(2, $connection->transactionLevel());

        $nestedId = $widgets->insertGetId([
            'name' => 'tx-nested-rollback',
            'created_at' => '2026-03-20 15:15:00',
            'updated_at' => '2026-03-20 15:15:00',
        ]);

        $connection->rollBack();
        self::assertSame(1, $connection->transactionLevel());
        self::assertSame(
            'tx-outer',
            $connection->table('widgets')->where('id', $outerId)->value('name')
        );
        self::assertNull($connection->table('widgets')->where('id', $nestedId)->value('name'));
        $connection->commit();

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(
            'tx-outer',
            $connection->table('widgets')->where('id', $outerId)->value('name')
        );
        self::assertNull($connection->table('widgets')->where('id', $nestedId)->value('name'));
    }

    public function test_it_runs_nullable_string_column_migrations_against_existing_tables(): void
    {
        $this->migrateBase();

        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'tenant-migrations';
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $migrator->usingConnection('firebird', function () use ($migrator, $path): void {
            $ran = array_values($migrator->run([$path]));
            self::assertCount(1, $ran);
            self::assertStringEndsWith('2026_06_20_000000_add_mnemo_to_widgets_table.php', $ran[0]);
        });

        $schema = Schema::connection('firebird');
        $connection = DB::connection('firebird');
        $mnemoColumn = collect($schema->getColumns('widgets'))
            ->first(fn (array $column): bool => strtolower($column['name']) === 'mnemo');

        self::assertNotNull($mnemoColumn);
        self::assertSame('varchar(50)', strtolower($mnemoColumn['type']));
        self::assertTrue((bool) $mnemoColumn['nullable']);

        $connection->table('widgets')->insert([
            'name' => 'tenant',
            'mnemo' => 'pda',
            'created_at' => '2026-06-20 10:00:00',
            'updated_at' => '2026-06-20 10:00:00',
        ]);

        self::assertSame('pda', $connection->table('widgets')->where('name', 'tenant')->value('mnemo'));

        $migrator->usingConnection('firebird', function () use ($migrator, $path): void {
            $rolledBack = array_values($migrator->rollback([$path]));
            self::assertCount(1, $rolledBack);
            self::assertStringEndsWith('2026_06_20_000000_add_mnemo_to_widgets_table.php', $rolledBack[0]);
        });

        self::assertFalse($schema->hasColumn('widgets', 'mnemo'));
    }

    private function migrateBase(): void
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'migrations';
        /** @var DatabaseMigrationRepository $repository */
        $repository = $this->app->make('migration.repository');
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $migrator->usingConnection('firebird', function () use ($repository, $migrator, $path): void {
            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }

            $ran = array_values($migrator->run([$path]));
            self::assertCount(1, $ran);
            self::assertStringEndsWith('2026_03_20_000000_create_widgets_table.php', $ran[0]);
        });
    }

    private function rollbackBase(): void
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'migrations';
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $migrator->usingConnection('firebird', function () use ($migrator, $path): void {
            $rolledBack = array_values($migrator->rollback([$path]));
            self::assertCount(1, $rolledBack);
            self::assertStringEndsWith('2026_03_20_000000_create_widgets_table.php', $rolledBack[0]);
        });
    }

    private function recreateDatabase(): void
    {
        $script = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'recreate-test-db.php';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            self::fail("Unable to recreate Firebird test database.\n".implode(PHP_EOL, $output));
        }
    }
}






