<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Schema;

use Illuminate\Database\Schema\Builder;

class FirebirdBuilder extends Builder
{
    public function dropAllTables()
    {
        $tables = array_column($this->getTables(), 'schema_qualified_name');

        foreach ($tables as $table) {
            $this->connection->statement(
                $this->grammar->compileDropAllTables([$table])
            );
        }
    }

    public function dropAllViews()
    {
        $views = array_column($this->getViews(), 'schema_qualified_name');

        foreach ($views as $view) {
            $this->connection->statement(
                $this->grammar->compileDropAllViews([$view])
            );
        }
    }

    public function getCurrentSchemaListing()
    {
        return [];
    }
}
