<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use PDO;

/**
 * Eloquent connection for GaussDB 507 over PDO_ODBC.
 *
 * Uses Postgres grammars because the SQL surface (quoted identifiers,
 * RETURNING, savepoints, ILIKE) is postgres-derived, then fixes the ODBC gaps:
 * lastInsertId is not provided by the ODBC driver and the server rejects
 * ON CONFLICT, so upserts are compiled to MERGE INTO.
 */
class GaussDBConnection extends Connection
{
    private ConnectionConfig $gaussConfig;

    public function __construct(
        PDO $pdo,
        string $database = '',
        string $tablePrefix = '',
        array $config = [],
        ?ConnectionConfig $gaussConfig = null
    ) {
        // The parent constructor resolves the default grammars, which read
        // gaussConfig — assign it first.
        $this->gaussConfig = $gaussConfig ?? new ConnectionConfig(
            'localhost',
            5432,
            $database !== '' ? $database : 'gaussdb',
            'gaussdb',
            '',
            'A'
        );
        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    protected function causedByLostConnection(\Throwable $e)
    {
        // ODBC surfaces a planned switchover as PostgreSQL admin/crash shutdown,
        // which Laravel's default message detector does not recognize.
        $state = $e instanceof \PDOException ? ($e->errorInfo[0] ?? (string) $e->getCode()) : (string) $e->getCode();
        return in_array($state, ['57P01', '57P02'], true) || parent::causedByLostConnection($e);
    }

    public function query()
    {
        return new GaussDBQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    public function gaussConfig(): ConnectionConfig
    {
        return $this->gaussConfig;
    }

    public function distributed(): bool
    {
        return $this->gaussConfig->distributed();
    }

    /**
     * The ODBC driver does not implement lastInsertId (SQLSTATE IM001). The
     * Postgres processor's RETURNING path handles insertGetId on both shapes;
     * this fallback serves code that calls lastInsertId directly. lastval()
     * exists on centralized deployments only — the distributed CN rejects it
     * with 0A000 — so the distributed path rejects calls rather than reading a global sequence value.
     */
    public function lastInsertId(?string $sequence = null)
    {
        if (! $this->distributed()) {
            $row = $this->selectOne('SELECT lastval() AS v');
            return $row !== null ? (int) ($row->v ?? $row['v'] ?? 0) : 0;
        }
        throw new \LogicException(
            'Distributed lastInsertId cannot safely read a global sequence value; use insertGetId (RETURNING)'
        );
    }

    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new GaussDBQueryGrammar($this);
    }

    protected function getDefaultPostProcessor(): Processor
    {
        return new GaussDBProcessor();
    }

    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new GaussDBSchemaGrammar($this);
    }

    public function getSchemaBuilder(): SchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->setSchemaGrammar($this->getDefaultSchemaGrammar());
        }
        return new GaussDBSchemaBuilder($this);
    }

    /**
     * ODBC reports ATTR_DRIVER_NAME 'odbc', not 'pgsql'; expose the real one.
     */
    public function getDriverName(): string
    {
        return 'gaussdb';
    }

    /**
     * The ODBC driver reports its own compatibility version (9.2.4), not the
     * server's; surface the true server version.
     */
    public function getServerVersion(): string
    {
        $row = $this->selectOne('SELECT version() AS v');
        $version = $row !== null ? (string) ($row->v ?? $row['v'] ?? '') : '';
        if (preg_match('/\(GaussDB Kernel ([^)]+)\)/', $version, $m)) {
            return $m[1];
        }
        return $version;
    }
}
