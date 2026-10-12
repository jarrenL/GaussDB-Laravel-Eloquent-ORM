<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

/**
 * Creates the PDO_ODBC connection for GaussDB and enforces the deployment
 * contract (version 507, A/ORA compatibility, matching topology).
 */
class Connector extends PostgresConnector
{
    /** @var array<string, mixed> */
    private const BASE_PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    private ConnectionConfig $config;

    public function __construct(ConnectionConfig $config)
    {
        // The base Connector has no constructor; we keep its option helpers
        // but build the DSN and PDO options ourselves.
        $this->config = $config;
    }

    /**
     * @param  array<string, mixed>  $options  ignored; see BASE_PDO_OPTIONS
     */
    public function connect(array $config = [], array $options = []): PDO
    {
        $pdo = $this->createConnection(
            $this->config->dsn(),
            ['username' => $this->config->user, 'password' => $this->config->password],
            $this->config->pdoOptions + self::BASE_PDO_OPTIONS
        );
        $this->assertDeployment($pdo);
        return $pdo;
    }

    private function assertDeployment(PDO $pdo): void
    {
        $version = (string) $pdo->query('SELECT version()')->fetchColumn();
        if (strpos($version, 'GaussDB') === false || !preg_match('/\b507\./', $version)) {
            throw new \RuntimeException("GaussDB 507 required; got: {$version}");
        }
        $compat = (string) $pdo->query(
            'SELECT datcompatibility FROM pg_database WHERE datname = current_database()'
        )->fetchColumn();
        $want = $this->config->mode;
        $ok = $want === 'A' ? $compat === 'A' : ($want === 'ORA' && $compat === 'ORA');
        if (!$ok) {
            throw new \RuntimeException("Compatibility mode {$want} required; database is {$compat}");
        }
        $dataNodes = (int) $pdo->query(
            "SELECT count(*) FROM pgxc_node WHERE node_type = 'D'"
        )->fetchColumn();
        $distributed = $this->config->distributed();
        if ($distributed !== ($dataNodes > 0)) {
            throw new \RuntimeException(
                "Deployment mismatch: data nodes = {$dataNodes}, distributed = " . var_export($distributed, true)
            );
        }
        // PDO_ODBC does not implement quote() (IM001); use a bound parameter.
        $schemaProbe = $pdo->prepare('SELECT count(*) FROM pg_namespace WHERE nspname = ?');
        $schemaProbe->execute([$this->config->schema]);
        $schemaExists = (int) $schemaProbe->fetchColumn();
        if ($schemaExists !== 1) {
            throw new \RuntimeException("Schema {$this->config->schema} must be provisioned before connecting");
        }
        $encoding = (string) $pdo->query('SHOW client_encoding')->fetchColumn();
        if (strtoupper(str_replace(['-', '_'], '', $encoding)) !== 'UTF8') {
            throw new \RuntimeException("Client encoding must be UTF8; got {$encoding}");
        }
    }
}
