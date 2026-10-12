<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

/**
 * Schema grammar for GaussDB 507. Adds `distribute by hash (...)` to CREATE
 * TABLE on distributed deployments and validates that primary keys and unique
 * indexes include the distribution key (a server-side requirement) before
 * the DDL is sent.
 */
class GaussDBSchemaGrammar extends PostgresGrammar
{
    private bool $distributed = false;
    private string $distributionKey = '';

    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        if ($connection instanceof GaussDBConnection) {
            $this->distributed = $connection->distributed();
            $this->distributionKey = $connection->gaussConfig()->distributionKey;
        }
    }

    /**
     * GaussDB has no attgenerated column on pg_attribute (generated columns
     * are not supported), so the introspection must emit a constant like the
     * pre-12 Postgres branch. format_type/pg_get_expr/col_description all
     * exist on 507.
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select a.attname as name, t.typname as type_name, format_type(a.atttypid, a.atttypmod) as type, '
            .'(select tc.collcollate from pg_catalog.pg_collation tc where tc.oid = a.attcollation) as collation, '
            .'not a.attnotnull as nullable, '
            .'(select pg_get_expr(adbin, adrelid) from pg_attrdef where c.oid = pg_attrdef.adrelid and pg_attrdef.adnum = a.attnum) as default, '
            ."'' as generated, "
            .'col_description(c.oid, a.attnum) as comment '
            .'from pg_attribute a, pg_class c, pg_type t, pg_namespace n '
            .'where c.relname = %s and n.nspname = %s and a.attnum > 0 and not a.attisdropped and a.attrelid = c.oid and a.atttypid = t.oid and n.oid = c.relnamespace '
            .'order by a.attnum',
            $this->quoteString($table),
            $schema ? $this->quoteString($schema) : 'current_schema()'
        );
    }

    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        $table = $blueprint->getTable();
        $schema = str_contains($table, '.') ? explode('.', $table, 2)[0] : null;
        $index = $command->index;
        return 'drop index ' . $this->wrap($schema !== null && !str_contains($index, '.') ? $schema.'.'.$index : $index);
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        $sql = parent::compileCreate($blueprint, $command);
        if ($this->distributed) {
            $this->assertDistributionRules($blueprint);
            $sql .= ' distribute by hash (' . $this->wrap($this->distributionKey) . ')';
        }
        return $sql;
    }

    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        $table = $blueprint->getTable();
        $schema = str_contains($table, '.') ? explode('.', $table, 2)[0] : null;
        $from = $command->from;
        if ($schema !== null && !str_contains($from, '.')) {
            $from = $schema.'.'.$from;
        }
        return 'alter index '.$this->wrap($from).' rename to '.$this->wrap($command->to);
    }

    /**
     * GaussDB distributed deployments require the distribution key inside the
     * primary key and every unique index; fail early with a clear message
     * instead of a server-side error after partial DDL.
     */
    private function assertDistributionRules(Blueprint $blueprint): void
    {
        $key = $this->distributionKey;
        if ($key === '') {
            throw new \RuntimeException('Distributed connections require a distribution key');
        }
        $column = collect($blueprint->getColumns())->first(fn ($c) => $c->name === $key);
        if ($column === null) {
            throw new \RuntimeException("Distribution key column \"{$key}\" is not defined on table {$blueprint->getTable()}");
        }
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'primary') {
                $columns = (array) $command->columns;
                if (! in_array($key, $columns, true)) {
                    throw new \RuntimeException("Primary key must include the distribution key \"{$key}\"");
                }
            }
            if ($command->name === 'unique') {
                $columns = (array) $command->columns;
                if (! in_array($key, $columns, true)) {
                    throw new \RuntimeException("Unique index on (" . implode(',', $columns) . ") must include the distribution key \"{$key}\"");
                }
            }
        }
    }
}
