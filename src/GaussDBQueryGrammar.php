<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Query grammar for GaussDB 507. Inherits the Postgres surface and replaces
 * the ON CONFLICT family (rejected by GaussDB with SQLSTATE 42601) with
 * MERGE INTO ... FROM dual, which works on both centralized and distributed
 * deployments. Row values keep Laravel's binding order: the flattened insert
 * values first, then the update assignment values.
 */
class GaussDBQueryGrammar extends PostgresGrammar
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

    public function distributed(): bool
    {
        return $this->distributed;
    }

    public function distributionKey(): string
    {
        return $this->distributionKey;
    }

    // An anti-join cannot preserve unique-conflict or concurrent-ignore semantics.
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new \LogicException('insertOrIgnore requires GaussDBQueryBuilder execution with savepoints; it cannot compile to a single SQL statement.');
    }

    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        throw new \LogicException('insertOrIgnoreUsing requires GaussDBQueryBuilder execution; it cannot compile to a single SQL statement.');
    }

    /**
     * `on conflict (…) do update set …` → MERGE INTO with MATCHED + NOT
     * MATCHED arms. Update bindings come after the insert-value bindings,
     * exactly like upstream upsert().
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        if (! is_array($uniqueBy)) {
            $uniqueBy = [$uniqueBy];
        }
        $columns = array_keys($values[0]);
        $assignments = [];
        foreach ($update as $key => $value) {
            if (is_numeric($key)) {
                // Numeric form copies the column from the source row; the
                // source exposes positional aliases c0..cN.
                $index = array_search($value, $columns, true);
                if ($index === false) {
                    throw new \InvalidArgumentException(
                        'Upsert update column '.$value.' is not part of the inserted columns'
                    );
                }
                $assignments[] = $this->wrap($value) . ' = s.' . $this->wrap('c' . $index);
            } else {
                $assignments[] = $this->wrap($key) . ' = ' . $this->parameter($value);
            }
        }
        return $this->compileMerge($query, $values, $assignments, $uniqueBy, $columns);
    }

    public function compileSelect(Builder $query)
    {
        if ($this->distributed && $query->lock !== null
            && ($query->limit !== null || $query->offset !== null)) {
            throw new \LogicException('Distributed GaussDB does not support locks with LIMIT/OFFSET; use a distribution-key predicate without pagination.');
        }
        return parent::compileSelect($query);
    }

    /**
     * @param  array<int, array<string, mixed>>  $values  insert rows (already ksorted by Builder)
     * @param  list<string>  $assignments  MATCHED-arm SET clauses
     * @param  list<string>  $uniqueBy  columns for the ON clause
     * @param  list<string>|null  $order  column order for the USING source; defaults to the row keys
     */
    private function compileMerge(Builder $query, array $values, array $assignments, array $uniqueBy, ?array $order = null): string
    {
        $columns = $order ?? array_keys($values[0]);
        $table = $this->wrapTable($query->from);

        $selects = [];
        foreach ($values as $row) {
            if (array_keys($row) !== $columns) {
                throw new \InvalidArgumentException('Upsert rows must have identical columns and order');
            }
            $items = [];
            foreach ($columns as $i => $column) {
                $value = $row[$column];
                $items[] = $this->parameter($value)
                    . ($this->isExpression($value) ? '' : $this->phpCastFor($value))
                    . ' as ' . $this->wrap('c' . $i);
            }
            $selects[] = 'select ' . implode(', ', $items) . ' from dual';
        }
        $source = '(' . implode(' union all ', $selects) . ') s ('
            . implode(', ', array_map(fn ($i) => $this->wrap('c' . $i), array_keys($columns))) . ')';
        if ($uniqueBy === []) {
            throw new \InvalidArgumentException('Upsert requires explicit uniqueBy columns');
        }

        $on = [];
        foreach ($uniqueBy as $column) {
            $index = array_search($column, $columns, true);
            if ($index === false) {
                throw new \InvalidArgumentException('Upsert uniqueBy column '.$column.' is not part of the inserted columns');
            }
            $on[] = 't.' . $this->wrap($column) . ' = s.' . $this->wrap('c' . $index);
        }

        $sql = "merge into {$table} t using {$source} on " . implode(' and ', $on);
        if ($assignments !== []) {
            $sql .= ' when matched then update set ' . implode(', ', $assignments);
        }
        $sql .= ' when not matched then insert (' . $this->columnize($columns) . ') values ('
            . implode(', ', array_map(fn ($i) => 's.' . $this->wrap('c' . $i), array_keys($columns))) . ')';
        return $sql;
    }

    /**
     * ODBC parameters arrive as text and GaussDB MERGE does not coerce them;
     * mirror the PHP value type in the source expression so boolean and
     * numeric columns type-check. Nulls and strings stay untyped (text
     * assigns to every character type; NULL is valid for any column).
     */
    private function phpCastFor(mixed $value): string
    {
        if (is_bool($value)) {
            return '::boolean';
        }
        if (is_int($value)) {
            return '::bigint';
        }
        if (is_float($value)) {
            return '::numeric';
        }
        if (is_string($value)) {
            return '::text';
        }
        // NULL stays untyped — valid for any column.
        return '';
    }

}
