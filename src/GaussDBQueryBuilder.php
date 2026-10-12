<?php

declare(strict_types=1);
namespace GaussDb\Eloquent;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/** Conflict handling happens at execution time so savepoints protect the caller's transaction. */
class GaussDBQueryBuilder extends Builder
{
    public function insertOrIgnore(array $values)
    {
        if ($values === []) { return 0; }
        if (!is_array(reset($values))) { $values = [$values]; }
        $this->applyBeforeQueryCallbacks();
        return $this->connection->transaction(function () use ($values) {
            $inserted = 0;
            foreach ($values as $row) {
                try {
                    $this->connection->transaction(function () use ($row) {
                        if (!(clone $this)->insert($row)) { throw new \RuntimeException('Insert failed'); }
                    });
                    $inserted++;
                } catch (QueryException $e) {
                    if ((string) $e->getCode() !== '23505') { throw $e; }
                }
            }
            return $inserted;
        });
    }

    public function insertOrIgnoreUsing(array $columns, $query)
    {
        if ($columns === [] || $columns === ['*']) {
            throw new \InvalidArgumentException('insertOrIgnoreUsing requires explicit target columns');
        }
        [$sql, $bindings] = $this->createSub($query);
        // Materialize on the write connection: active ODBC cursors cannot safely share writes.
        return $this->connection->transaction(function () use ($columns, $sql, $bindings) {
            $rows = $this->connection->cursor($sql, $bindings, false);
            $maximum = (int) ($this->connection->getConfig('gaussdb_ignore_using_max_rows') ?? 10000);
            if ($maximum < 1) { throw new \InvalidArgumentException('Ignore source row limit must be positive'); }
            $values = [];
            foreach ($rows as $row) {
                if (count($values) >= $maximum) {
                    throw new \LengthException('insertOrIgnoreUsing source exceeds the configured row limit');
                }
                $data = array_values((array) $row);
                if (count($data) !== count($columns)) {
                    throw new \InvalidArgumentException('Source and target column counts differ');
                }
                $values[] = array_combine($columns, $data);
            }
            return $this->insertOrIgnore($values);
        });
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        if ($values === []) { return 0; }
        $rows = is_array(reset($values)) ? $values : [$values];
        $seen = [];
        foreach ($rows as $row) {
            $key = [];
            foreach ((array) $uniqueBy as $column) {
                if (!array_key_exists($column, $row) || $this->grammar->isExpression($row[$column])) {
                    throw new \InvalidArgumentException('Upsert keys must be present and scalar');
                }
                if ($row[$column] === null) { continue 2; }
                $key[] = (string) $row[$column];
            }
            $encoded = serialize($key);
            if (isset($seen[$encoded])) {
                throw new \InvalidArgumentException('Duplicate upsert source key; split or deduplicate the batch explicitly');
            }
            $seen[$encoded] = true;
        }
        // A competing MERGE may insert after our snapshot. Roll back the failed
        // statement/savepoint and retry against the now-committed row.
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->connection->transaction(fn () => parent::upsert($values, $uniqueBy, $update));
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23505' || $attempt >= 3) { throw $e; }
                usleep(10000 * ($attempt + 1));
            }
        }
    }
}
