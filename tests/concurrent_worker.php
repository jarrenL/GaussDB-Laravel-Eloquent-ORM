<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use GaussDb\Eloquent\CapsuleBoot;
use GaussDb\Eloquent\ConnectionConfig;

try {
    $conn = CapsuleBoot::makeConnection(new ConnectionConfig(
        getenv('GAUSS_HOST') ?: 'host.docker.internal', (int) (getenv('GAUSS_PORT') ?: 55452),
        getenv('GAUSS_DATABASE') ?: '', getenv('GAUSS_USER') ?: '', getenv('GAUSS_PASSWORD') ?: '',
        getenv('GAUSS_MODE') ?: 'A', getenv('GAUSS_SCHEMA') ?: 'public',
        getenv('GAUSS_DISTRIBUTED') === 'true' ? 'id' : ''
    ));
    echo "READY\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing start barrier');
    }
    $table = $argv[2];
    if ($argv[1] === 'race') {
        try {
            $conn->table($table)->insert(['id' => 42, 'tag' => 'worker']);
            echo "ok\n";
        } catch (Throwable $e) {
            if ((string) $e->getCode() !== '23505') {
                throw $e;
            }
            echo "dup\n";
        }
    } elseif ($argv[1] === 'soak') {
        $deadline = microtime(true) + (int)(getenv('SOAK_SECONDS') ?: 90);
        $committed=0; $rolledBack=0; $connections=1;
        while (microtime(true)<$deadline) {
            $conn->beginTransaction();
            try {
                $conn->table($table)->where('id',1)->increment('qty');
                if (($committed+$rolledBack)%7===0) { $conn->rollBack(); $rolledBack++; }
                else { $conn->commit(); $committed++; }
            } catch (Throwable $e) { $conn->rollBack(); throw $e; }
            if (($committed+$rolledBack)%100===0) {
                $conn->selectOne('select 1 as v');
                $conn->disconnect();
                $conn=CapsuleBoot::makeConnection(new ConnectionConfig(
                    getenv('GAUSS_HOST') ?: 'host.docker.internal',(int)getenv('GAUSS_PORT'),
                    getenv('GAUSS_DATABASE'),getenv('GAUSS_USER'),getenv('GAUSS_PASSWORD'),
                    getenv('GAUSS_MODE') ?: 'A',getenv('GAUSS_SCHEMA') ?: 'public',
                    getenv('GAUSS_DISTRIBUTED')==='true'?'id':''));
                $connections++;
            }
        }
        echo json_encode(['committed'=>$committed,'rolled_back'=>$rolledBack,'connections'=>$connections,'peak_bytes'=>memory_get_peak_usage(true)]), "\n";
    } elseif ($argv[1] === 'ignore') {
        echo $conn->table($table)->insertOrIgnore(['id'=>(int)$argv[3], 'name'=>'ignore_worker']), "\n";
    } elseif ($argv[1] === 'upsert') {
        $conn->table($table)->upsert([['id'=>(int)$argv[3], 'name'=>'upsert_worker']], ['id'], ['name']);
        echo "ok\n";
    } else {
        for ($i = 0; $i < 20; $i++) {
            $conn->table($table)->where('id', (int) $argv[3])->increment('qty');
        }
        echo "ok\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
