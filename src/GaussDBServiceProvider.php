<?php

declare(strict_types=1);
namespace GaussDb\Eloquent;

use Illuminate\Support\ServiceProvider;

/** Register GaussDB with Laravel's manager, including read/write and sticky routing. */
final class GaussDBServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving('db', function ($manager): void {
            $manager->extend('gaussdb', static function (array $config, string $name): GaussDBConnection {
                [$pdo, $settings] = self::connectEndpoints($config, 'write');
                $config['name'] = $name;
                $connection = new GaussDBConnection(
                    $pdo, $settings->database,
                    (string) ($config['prefix'] ?? ''), $config, $settings
                );
                if (isset($config['read'])) {
                    foreach (self::endpoints($config, 'read') as $read) {
                        $readSettings = self::settings($read);
                        foreach (['database', 'schema', 'mode', 'distributionKey'] as $key) {
                            if ($readSettings->$key !== $settings->$key) {
                                throw new \InvalidArgumentException('Read and write endpoints must use the same '.$key);
                            }
                        }
                    }
                    $connection->setReadPdo(static fn () => self::connectEndpoints($config, 'read')[0]);
                }
                return $connection;
            });
        });
    }

    /** Explicit endpoint lists are tried in order; queries in transactions are never replayed here. */
    private static function connectEndpoints(array $config, string $type): array
    {
        $last = null;
        foreach (self::endpoints($config, $type) as $endpoint) {
            $settings = self::settings($endpoint);
            try {
                $pdo = (new Connector($settings))->connect();
                if ($type === 'write') {
                    $recovery = $pdo->query('SELECT pg_is_in_recovery()')->fetchColumn();
                    if (in_array($recovery, [true, 't', '1', 1], true)) {
                        throw new \RuntimeException('Write endpoint is in recovery');
                    }
                }
                return [$pdo, $settings];
            } catch (\PDOException | \RuntimeException $e) { $last = $e; }
        }
        throw new \RuntimeException('No usable GaussDB '.$type.' endpoint', 0, $last);
    }

    private static function endpoints(array $config, string $type): array
    {
        $override = $config[$type] ?? [];
        $overrides = array_is_list($override) && $override !== [] ? $override : [$override];
        $endpoints = [];
        foreach ($overrides as $item) {
            $endpoint = array_replace($config, $item);
            $hosts = is_array($endpoint['host'] ?? null) ? $endpoint['host'] : [$endpoint['host'] ?? 'localhost'];
            if ($hosts === []) { throw new \InvalidArgumentException('Empty GaussDB host list'); }
            foreach ($hosts as $host) { $endpoints[] = array_replace($endpoint, ['host' => $host]); }
        }
        return $endpoints;
    }

    private static function settings(array $config): ConnectionConfig
    {
        return new ConnectionConfig(
            (string) ($config['host'] ?? 'localhost'), (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''), (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''), (string) ($config['mode'] ?? 'A'),
            (string) ($config['schema'] ?? 'public'), (string) ($config['distribution_key'] ?? ''),
            (string) ($config['sslmode'] ?? 'prefer'), (string) ($config['odbc_driver'] ?? 'GaussDB Unicode'),
            (array) ($config['options'] ?? []),
            (string) ($config['sslrootcert'] ?? ''), (string) ($config['sslcert'] ?? ''), (string) ($config['sslkey'] ?? ''), (string) ($config['target_session_attrs'] ?? '')
        );
    }
}
