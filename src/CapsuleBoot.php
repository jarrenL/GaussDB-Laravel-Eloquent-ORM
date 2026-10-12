<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Bootstrap helper: registers a GaussDB connection with Capsule and returns
 * the configured connection, ready for Schema::/DB:: facades and Eloquent
 * models.
 *
 * Usage:
 *
 *   $conn = GaussDb\Eloquent\CapsuleBoot::connect(new ConnectionConfig(
 *       '127.0.0.1', 55452, 'tortoise_central', 'tortoise_lab', $password, 'A'
 *   ));
 *   GaussDb\Eloquent\CapsuleBoot::schema()->create('users', function ($t) {
 *       $t->id(); $t->string('name');
 *   });
 */
final class CapsuleBoot
{
    private static ?Capsule $capsule = null;
    private static ?GaussDBConnection $connection = null;
    private static string $connectionName = 'gaussdb';

    public static function connect(
        ConnectionConfig $config,
        ?string $connectionName = null,
        ?Container $container = null
    ): GaussDBConnection {
        $name = $connectionName ?? 'gaussdb';
        if (self::$capsule !== null && $container !== null && $container !== self::$capsule->getContainer()) {
            throw new \InvalidArgumentException('Capsule is already initialized with another container');
        }
        $capsule = self::$capsule ?? new Capsule($container);
        $manager = $capsule->getDatabaseManager();
        $manager->purge($name);
        $capsule->addConnection(['driver' => 'gaussdb', 'name' => $name], $name);
        $manager->extend($name, function () use ($config, $name) {
            return self::makeConnection($config, $name);
        });
        self::$connectionName = $name;

        // Eloquent models resolve their connection through the default name,
        // so the registered name must also be the default.
        $capsule->getContainer()['config']['database.default'] = self::$connectionName;
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $connection = $manager->connection(self::$connectionName);
        if (! $connection instanceof GaussDBConnection) {
            throw new \RuntimeException('Unexpected connection type: ' . get_class($connection));
        }
        self::$capsule = $capsule;
        self::$connection = $connection;
        return $connection;
    }

    public static function makeConnection(ConnectionConfig $config, ?string $name = null): GaussDBConnection
    {
        $connector = new Connector($config);
        $pdo = $connector->connect();
        return new GaussDBConnection(
            $pdo,
            $config->database,
            '',
            [
                'name' => $name ?? self::$connectionName,
                'driver' => 'gaussdb',
                'host' => $config->host,
                'port' => $config->port,
                'database' => $config->database,
                'schema' => $config->schema,
                'gaussdb' => [
                    'mode' => $config->mode,
                    'distributed' => $config->distributed(),
                    'distribution_key' => $config->distributionKey,
                ],
            ],
            $config
        );
    }

    public static function schema(): GaussDBSchemaBuilder
    {
        if (self::$connection === null) {
            throw new \RuntimeException('Call connect() first');
        }
        return self::$connection->getSchemaBuilder();
    }

    public static function connection(): GaussDBConnection
    {
        if (self::$connection === null) {
            throw new \RuntimeException('Call connect() first');
        }
        return self::$connection;
    }

    public static function capsule(): Capsule
    {
        if (self::$capsule === null) {
            throw new \RuntimeException('Call connect() first');
        }
        return self::$capsule;
    }
}
