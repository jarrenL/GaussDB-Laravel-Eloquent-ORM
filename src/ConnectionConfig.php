<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use InvalidArgumentException;

/**
 * GaussDB connection configuration for the Eloquent adapter.
 *
 * Builds the PDO_ODBC DSN for the official GaussDB Unicode ODBC driver and
 * carries the deployment-specific settings the adapter layers on top.
 */
final class ConnectionConfig
{
    public const MODE_A = 'A';
    public const MODE_ORA = 'ORA';

    /** @var array<string> */
    private const ACCEPTED_MODES = [self::MODE_A, self::MODE_ORA];

    public string $host;
    public int $port;
    public string $database;
    public string $user;
    public string $password;
    /** 'A' (centralized) or 'ORA' (distributed O-compatible). */
    public string $mode;
    public string $schema;
    /** Distribution key column for distributed deployments; empty otherwise. */
    public string $distributionKey;
    public string $sslMode;
    public string $odbcDriver;
    public string $sslRootCert;
    public string $sslCert;
    public string $sslKey;
    public string $targetSessionAttrs;
    /** @var array<string, mixed> */
    public array $pdoOptions;

    /**
     * @param array<string, mixed> $pdoOptions
     */
    public function __construct(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
        string $mode,
        string $schema = 'public',
        string $distributionKey = '',
        string $sslMode = 'prefer',
        string $odbcDriver = 'GaussDB Unicode',
        array $pdoOptions = [],
        string $sslRootCert = '',
        string $sslCert = '',
        string $sslKey = '',
        string $targetSessionAttrs = ''
    ) {
        if ($host === '' || $database === '' || $user === '') {
            throw new InvalidArgumentException('Host, database, and user must not be empty');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port: {$port}");
        }
        $normalized = strtoupper($mode);
        $aliases = ['O' => self::MODE_ORA, 'ORACLE' => self::MODE_ORA];
        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }
        if (!in_array($normalized, self::ACCEPTED_MODES, true)) {
            throw new InvalidArgumentException("Mode must be A or ORA; got {$mode}");
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
            throw new InvalidArgumentException('Schema must be a lowercase identifier');
        }
        if ($distributionKey !== '' && !preg_match('/^[a-z_][a-z0-9_]*$/', $distributionKey)) {
            throw new InvalidArgumentException('Distribution key must be a lowercase identifier');
        }
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
        $this->mode = $normalized;
        $this->schema = $schema;
        $this->distributionKey = $distributionKey;
        if (!in_array($sslMode, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
            throw new InvalidArgumentException('Invalid SSL mode');
        }
        $this->sslMode = $sslMode;
        $this->sslRootCert = $sslRootCert;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        if (!in_array($targetSessionAttrs, ['', 'primary', 'cluster-primary', 'cluster-mainnode'], true)) {
            throw new InvalidArgumentException('Invalid GaussDB target_session_attrs');
        }
        $this->targetSessionAttrs = $targetSessionAttrs;
        $this->odbcDriver = $odbcDriver;
        $this->pdoOptions = $pdoOptions;
    }

    public function distributed(): bool
    {
        return $this->mode === self::MODE_ORA && $this->distributionKey !== '';
    }

    public function dsn(): string
    {
        $parts = [
            'Driver=' . $this->bracedDriver(),
            'Servername=' . $this->scalar($this->host),
            'Port=' . $this->port,
            'Database=' . $this->scalar($this->database),
            'SSLmode=' . $this->scalar($this->sslMode),
            'ConnSettings=set client_encoding=UTF8',
            'BoolsAsChar=0',
            'ByteaAsLongVarBinary=1',
        ];
        $tls = [];
        foreach (['sslrootcert'=>$this->sslRootCert, 'sslcert'=>$this->sslCert, 'sslkey'=>$this->sslKey] as $key=>$path) {
            if ($path !== '') {
                $value = $this->scalar($path);
                $tls[] = $key . "='" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
            }
        }
        if ($this->targetSessionAttrs !== '') { $parts[] = 'TargetServerType=' . $this->targetSessionAttrs; }
        if ($tls !== []) { $parts[] = 'Pqopt=' . implode(' ', $tls); }
        return 'odbc:' . implode(';', $parts);
    }

    private function scalar(string $value): string
    {
        if (strpbrk($value, ";{}\0") !== false) {
            throw new InvalidArgumentException('ODBC connection values must not contain semicolons, braces, or NUL bytes');
        }
        return $value;
    }

    private function bracedDriver(): string
    {
        if ($this->odbcDriver === '' || strpos($this->odbcDriver, "\0") !== false) {
            throw new InvalidArgumentException('ODBC driver name must not be empty or contain NUL bytes');
        }
        return '{' . str_replace('}', '}}', $this->odbcDriver) . '}';
    }
}
