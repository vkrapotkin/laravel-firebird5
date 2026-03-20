<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar;
use RuntimeException;
use Vkrapotkin\LaravelFirebird5\Query\Grammars\FirebirdGrammar as FirebirdQueryGrammar;
use Vkrapotkin\LaravelFirebird5\Query\Processors\FirebirdProcessor;
use Vkrapotkin\LaravelFirebird5\Schema\FirebirdBuilder;
use Vkrapotkin\LaravelFirebird5\Schema\Grammars\FirebirdGrammar as FirebirdSchemaGrammar;

class FirebirdConnection extends Connection
{
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        $grammar = new FirebirdQueryGrammar($this);
        $grammar->setTablePrefix($this->getTablePrefix());

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

        return $grammar;
    }

    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new FirebirdBuilder($this);
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

        throw new RuntimeException('Firebird connection does not support nested transactions via PDO.');
    }
}
