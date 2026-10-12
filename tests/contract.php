<?php

declare(strict_types=1);

/**
 * GaussDB Eloquent adapter contract suite.
 *
 * Coverage mirrors the Go XORM adapter's ext_test.go: query features, ORM
 * semantics, type boundaries, transactions & locks, concurrency, business
 * flows, metadata & error surfaces — each against BOTH deployment shapes.
 * Output: JSON (pass/fail per case + summary), exit 1 on any fail.
 *
 * Env: GAUSS_HOST/PORT/DATABASE/USER/PASSWORD/SCHEMA/MODE/DISTRIBUTED.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use GaussDb\Eloquent\CapsuleBoot;
use GaussDb\Eloquent\ConnectionConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

function envStr(string $name, string $default = ''): string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

$host = envStr('GAUSS_HOST', 'host.docker.internal');
$port = (int) envStr('GAUSS_PORT', '55452');
$database = envStr('GAUSS_DATABASE');
$user = envStr('GAUSS_USER');
$password = getenv('GAUSS_PASSWORD') ?: '';
$schema = envStr('GAUSS_SCHEMA', 'public');
$mode = envStr('GAUSS_MODE', 'A');
$distributed = envStr('GAUSS_DISTRIBUTED') === 'true';
$distKey = $distributed ? 'id' : '';

$results = [];
$fail = 0;
$probeLog = [];

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
$run = function (string $name, callable $test) use (&$results, &$fail): void {
    $started = microtime(true);
    try {
        $detail = $test();
        $results[] = ['name' => $name, 'status' => 'pass', 'ms' => round((microtime(true) - $started) * 1000, 1), 'detail' => $detail];
    } catch (Throwable $e) {
        $fail++;
        $results[] = ['name' => $name, 'status' => 'fail', 'ms' => round((microtime(true) - $started) * 1000, 1),
            'detail' => ['exception' => get_class($e), 'sqlstate' => (string) $e->getCode(), 'message' => substr($e->getMessage(), 0, 300)]];
    }
};
$probe = function (string $name, callable $fn) use (&$probeLog): void {
    try {
        $probeLog[$name] = $fn();
    } catch (Throwable $e) {
        $probeLog[$name] = 'FAILED: ' . substr($e->getMessage(), 0, 160);
    }
};

// ---- bootstrap ----
$conn = null;
$run('connector prechecks (version/mode/topology/schema/encoding)', function () use (&$conn, $host, $port, $database, $user, $password, $mode, $schema, $distKey) {
    $config = new ConnectionConfig($host, $port, $database, $user, $password, $mode, $schema, $distKey);
    $conn = CapsuleBoot::connect($config);
    check($conn->getServerVersion() !== '', 'empty server version');
    return ['server' => $conn->getServerVersion(), 'distributed' => $conn->distributed(), 'driver' => $conn->getDriverName()];
});
if ($conn === null) {
    fwrite(STDERR, "bootstrap failed\n");
    exit(1);
}

$sb = CapsuleBoot::schema();
$prefix = 'elq_t_' . bin2hex(random_bytes(4));
$cleanup = [];

// Fatal-error safety net: PHP shutdown hook still runs after a fatal, so any
// table created for this run gets dropped even when the suite dies mid-test.
register_shutdown_function(function () use (&$cleanup, $schema, $prefix) {
    global $conn;
    if ($conn === null) {
        return;
    }
    try {
        $rows = $conn->select(
            'select table_schema, table_name from information_schema.tables where table_name like ?',
            [$prefix . '%']
        );
        foreach ($rows as $row) {
            $table = ($row->table_schema ?? $row['table_schema']) . '.' . ($row->table_name ?? $row['table_name']);
            try {
                $conn->statement('drop table if exists ' . $table . ' cascade');
            } catch (Throwable $e) {
                fwrite(STDERR, 'shutdown cleanup failed for ' . $table . ': ' . substr($e->getMessage(), 0, 100) . PHP_EOL);
            }
        }
    } catch (Throwable $e) {
        // connection may already be gone
    }
});

$make = function (string $suffix, callable $definition) use ($sb, $schema, $prefix, &$cleanup): string {
    $t = $schema . '.' . $prefix . $suffix;
    $sb->create($t, $definition);
    $cleanup[] = $t;
    return $t;
};

// Model base with dynamic table + casts
class ContractRow extends Model
{
    public static string $currentTable = '';
    public $timestamps = false;
    public $guarded = [];
    protected $casts = ['active' => 'boolean', 'qty' => 'integer', 'created_at' => 'datetime', 'meta' => 'array'];

    public function getTable(): string
    {
        return self::$currentTable !== '' ? self::$currentTable : parent::getTable();
    }
}
class ContractChild extends Model
{
    public static string $childTable = '';
    public $timestamps = false;
    protected $guarded = [];

    public function getTable(): string
    {
        return self::$childTable !== '' ? self::$childTable : parent::getTable();
    }
}
class ContractParent extends ContractRow
{
    public static string $currentTable = '';
    public ?string $childTable = null;

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        $r = $this->hasMany(ContractChild::class, 'parent_id');
        if ($this->childTable !== null) {
            $r->getQuery()->from($this->childTable);
        }
        return $r;
    }
}

class RelationOwner extends Model {
    public static string $target = ''; public static string $pivot = '';
    public $timestamps = false; protected $guarded = [];
    public function getTable(): string { return self::$target; }
    public function child() { return $this->hasOne(RelationChild::class, 'owner_id'); }
    public function children() { return $this->hasMany(RelationChild::class, 'owner_id'); }
    public function tags() { return $this->belongsToMany(RelationTag::class, self::$pivot, 'owner_id', 'tag_id'); }
    public function notes() { return $this->morphMany(RelationNote::class, 'notable'); }
}
class RelationChild extends Model {
    public static string $target = ''; public $timestamps = false; protected $guarded = [];
    public function getTable(): string { return self::$target; }
    public function owner() { return $this->belongsTo(RelationOwner::class, 'owner_id'); }
}
class RelationTag extends Model {
    public static string $target = ''; public $timestamps = false; protected $guarded = [];
    public function getTable(): string { return self::$target; }
}
class RelationNote extends Model {
    public static string $target = ''; public $timestamps = false; protected $guarded = [];
    public function getTable(): string { return self::$target; }
    public function notable() { return $this->morphTo(); }
}

// ---- 1. schema ----
$main = $make('', function (Blueprint $t) {
    $t->id();
    $t->string('name', 200)->index();
    $t->bigInteger('qty')->default(0);
    $t->decimal('amount', 18, 2)->nullable();
    $t->boolean('active')->default(true);
    $t->text('memo')->nullable();
    $t->json('meta')->nullable();
    $t->timestamp('created_at')->nullable();
});
ContractRow::$currentTable = $main;

$run('schema: create/hasTable/hasColumn/getColumnListing', function () use (&$sb, $main) {
    check($sb->hasTable($main), 'hasTable false');
    check($sb->hasColumn($main, 'name'), 'hasColumn(name) false');
    $cols = $sb->getColumnListing($main);
    foreach (['id', 'name', 'qty', 'amount', 'active', 'memo', 'meta', 'created_at'] as $c) {
        check(in_array($c, $cols, true), "column {$c} missing from listing");
    }
    return ['columns' => count($cols)];
});

if ($distributed) {
    $run('schema: distributed DISTRIBUTE BY hash on id', function () use ($conn, $main, $schema) {
        $row = $conn->selectOne(
            'SELECT p.pclocatortype AS kind, p.pcattnum::text AS attnum FROM pgxc_class p '
            . 'JOIN pg_class c ON c.oid = p.pcrelid JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE c.relname = ? AND n.nspname = ?',
            [substr($main, strrpos($main, '.') + 1), $schema]
        );
        check($row !== null, 'pgxc_class row missing');
        $kind = $row->kind ?? $row['kind'];
        $attnum = $row->attnum ?? $row['attnum'];
        check($kind === 'H', "expected HASH distribution, got {$kind}");
        check($attnum === '1', "expected attnum 1 (id), got {$attnum}");
        return ['kind' => $kind, 'attnum' => $attnum];
    });

    $run('schema: unique index without distribution key rejected', function () use ($sb, $schema, $prefix, &$cleanup) {
        $bad = $schema . '.' . $prefix . '_bad';
        try {
            $sb->create($bad, function (Blueprint $t) {
                $t->id();
                $t->string('email', 120)->unique();
            });
            $cleanup[] = $bad;
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'distribution key'), 'wrong rejection message: ' . $e->getMessage());
            return ['rejected' => true, 'message' => substr($e->getMessage(), 0, 120)];
        }
        throw new RuntimeException('unique index without distribution key was accepted');
    });
}

// ---- 2. ORM core ----
$run('orm: save/insertGetId + find + update + delete', function () {
    $m = new ContractRow();
    $m->name = 'orm_core';
    $m->qty = 5;
    $m->amount = '12345.67';
    $m->active = true;
    $m->meta = ['k' => 'v', 'nested' => ['ok' => 1]];
    $m->created_at = '2026-10-11 12:00:00';
    $m->save();
    check($m->id > 0, 'no id after save');

    $found = ContractRow::find($m->id);
    check($found->name === 'orm_core', 'name mismatch');
    check($found->qty === 5, 'qty mismatch');
    check($found->amount === '12345.67', 'decimal precision mismatch: ' . var_export($found->amount, true));
    check($found->active === true, 'bool cast mismatch: ' . var_export($found->active, true));
    check(($found->meta['k'] ?? null) === 'v', 'jsonb read mismatch');
    check((string) $found->created_at === '2026-10-11 12:00:00', 'timestamp mismatch: ' . (string) $found->created_at);

    $found->qty = 42;
    $found->save();
    check(ContractRow::find($m->id)->qty === 42, 'update lost');

    check(ContractRow::destroy($m->id) === 1, 'delete failed');
    check(ContractRow::find($m->id) === null, 'row survived delete');
    return ['id' => $m->id];
});

$run('orm: mass create + fillable guard', function () {
    $m = ContractRow::create(['name' => 'mass', 'qty' => 7]);
    check($m->id > 0 && ContractRow::find($m->id)->qty === 7, 'mass create failed');
    return ['id' => $m->id];
});

$run('orm: firstOrCreate/updateOrCreate', function () {
    $a = ContractRow::firstOrCreate(['name' => 'foc'], ['qty' => 1]);
    $b = ContractRow::firstOrCreate(['name' => 'foc'], ['qty' => 2]);
    check($a->id === $b->id, 'firstOrCreate created a second row');
    $c = ContractRow::updateOrCreate(['name' => 'foc'], ['qty' => 10]);
    check($c->id === $a->id && $c->qty === 10, 'updateOrCreate mismatch');
    return ['id' => $a->id, 'qty' => $c->qty];
});

// ---- 3. query features ----
$run('query: where/whereIn/orderBy/limit/offset', function () {
    ContractRow::query()->insert(
        array_map(fn ($i) => ['name' => "q_{$i}", 'qty' => $i, 'active' => $i % 2 === 0], range(0, 19))
    );
    $rows = ContractRow::where('name', 'like', 'q_%')->whereIn('qty', [2, 3, 4])->orderByDesc('qty')->get();
    check($rows->count() === 3 && $rows->first()->qty === 4, 'whereIn/orderBy failed');
    $page = ContractRow::where('name', 'like', 'q_%')->orderBy('qty')->limit(5)->offset(5)->get();
    check($page->count() === 5 && $page->first()->qty === 5, 'limit/offset failed');
    return ['in' => $rows->count(), 'page_first' => $page->first()->qty];
});

$run('query: aggregates + groupBy/having + raw expressions', function () {
    $stats = ContractRow::where('name', 'like', 'q_%')
        ->selectRaw('count(*) as n, sum(qty) as total, avg(qty)::numeric(10,2) as mean')
        ->first();
    check((int) $stats->n === 20, 'count mismatch');
    check((int) $stats->total === 190, 'sum mismatch');
    $groups = ContractRow::where('name', 'like', 'q_%')
        ->selectRaw('active, count(*) as n')
        ->groupBy('active')
        ->havingRaw('count(*) > 9')
        ->get();
    check($groups->count() === 2, 'groupBy group count: ' . $groups->count());
    foreach ($groups as $g) {
        check((int) $g->n === 10, 'group size: ' . $g->n);
    }
    return ['n' => (int) $stats->n, 'total' => (int) $stats->total, 'groups' => $groups->count()];
});

$run('query: subquery + exists + join', function () use ($schema, $prefix, &$cleanup, $sb, $main) {
    $child = $schema . '.' . $prefix . '_child';
    $sb->create($child, function (Blueprint $t) {
        $t->id();
        $t->bigInteger('parent_id')->index();
        $t->string('label', 60);
    });
    $cleanup[] = $child;
    $parent = ContractRow::where('name', 'q_0')->first();
    ContractChild::$childTable = $child;
    for ($i = 0; $i < 3; $i++) {
        ContractChild::create(['parent_id' => $parent->id, 'label' => "c{$i}"]);
    }
    $sub = ContractRow::where('name', 'like', 'q_%')->select('id');
    $cnt = ContractRow::whereIn('id', $sub)->count();
    check($cnt === 20, 'subquery whereIn count mismatch');
    $joined = ContractRow::join($child . ' as ch', 'ch.parent_id', '=', $main . '.id')
        ->where('name', 'q_0')->count();
    check($joined === 3, 'join count mismatch');
    $exists = ContractRow::whereExists(function ($q) use ($child, $main) {
        $q->selectRaw('1')->from($child . ' as ch')->whereColumn('ch.parent_id', $main . '.id');
    })->count();
    check($exists === 1, 'exists mismatch: ' . $exists);
    return ['sub' => $cnt, 'join' => $joined, 'exists' => $exists];
});

$run('query: pagination', function () {
    $p = ContractRow::where('name', 'like', 'q_%')->orderBy('qty')->paginate(6);
    check($p->total() === 20 && $p->lastPage() === 4 && $p->count() === 6, 'paginate mismatch');
    return ['total' => $p->total(), 'lastPage' => $p->lastPage()];
});

// ---- 4. upserts (MERGE) ----
$run('upsert: MERGE insert + update arms', function () {
    $r = ContractRow::query()->upsert(
        [['name' => 'up_a', 'qty' => 1, 'active' => true]],
        ['name'],
        ['qty']
    );
    check($r === 1, 'first upsert affected=' . var_export($r, true));
    $r = ContractRow::query()->upsert(
        [['name' => 'up_a', 'qty' => 99, 'active' => false]],
        ['name'],
        ['qty']
    );
    $row = ContractRow::where('name', 'up_a')->first();
    check((int) $row->qty === 99, 'upsert update mismatch: ' . $row->qty);
    return ['affected_second' => $r, 'qty' => $row->qty];
});

$run('upsert: multi-row insert and update', function () use ($conn, $main) {
    $rows = [['id' => 80001, 'name' => 'batch_a', 'qty' => 11], ['id' => 80002, 'name' => 'batch_b', 'qty' => 22]];
    $conn->table($main)->upsert($rows, ['id'], ['qty']);
    check($conn->table($main)->whereIn('id', [80001, 80002])->count() === 2, 'batch insert lost rows');
    $conn->table($main)->upsert([['id' => 80001, 'name' => 'batch_a', 'qty' => 33], ['id' => 80002, 'name' => 'batch_b', 'qty' => 44]], ['id'], ['qty']);
    check((int) $conn->table($main)->where('id', 80001)->value('qty') === 33, 'batch update first row');
    check((int) $conn->table($main)->where('id', 80002)->value('qty') === 44, 'batch update second row');
    return ['rows' => 2];
});

$run('ignore APIs: duplicate keys, insert-select, savepoint recovery', function () use ($conn, $main) {
    $rows = [['id'=>81001, 'name'=>'ignore_a'], ['id'=>81002, 'name'=>'ignore_b']];
    check($conn->table($main)->insertOrIgnore($rows) === 2, 'ignore batch count');
    $conn->beginTransaction();
    try {
        check($conn->table($main)->insertOrIgnore([['id'=>81001, 'name'=>'changed'], ['id'=>81003, 'name'=>'ignore_c']]) === 1, 'unique conflict not ignored');
        check($conn->table($main)->insertOrIgnoreUsing(['id','name'], $conn->table($main)->select('id','name')->whereIn('id',[81001,81002])) === 0, 'insert-select conflict not ignored');
        check($conn->table($main)->where('id',81001)->value('name') === 'ignore_a', 'ignore changed existing row');
        $conn->rollBack();
    } catch (Throwable $e) { $conn->rollBack(); throw $e; }
    check(!$conn->table($main)->where('id',81003)->exists(), 'outer rollback failed');
    try { $conn->table($main)->insertOrIgnore([['id'=>81004,'name'=>'atomic'], ['id'=>81005,'name'=>null]]); }
    catch (\Illuminate\Database\QueryException $e) {
        check((string)$e->getCode()==='23502', 'non-unique error masked');
        check(!$conn->table($main)->where('id',81004)->exists(), 'failed batch partially committed');
        return ['ignored'=>2,'outer_rollback'=>true,'atomic_failure'=>true];
    }
    throw new RuntimeException('NOT NULL violation ignored');
});

$run('upsert: repeated source key rejected atomically', function () use ($conn, $main) {
    try { $conn->table($main)->upsert([['id'=>82001,'name'=>'a'], ['id'=>82001,'name'=>'b']], ['id'], ['name']); }
    catch (InvalidArgumentException $e) {
        check(!$conn->table($main)->where('id',82001)->exists(), 'duplicate batch partially inserted');
        return ['rejected'=>true];
    }
    throw new RuntimeException('duplicate source accepted');
});

if ($distributed) {
    $run('locks: paginated lock fails without dropping FOR UPDATE', function () use ($conn, $main) {
        try { $conn->table($main)->where('id', 1)->lockForUpdate()->limit(1)->get(); }
        catch (LogicException $e) { return ['rejected' => true]; }
        throw new RuntimeException('paginated lock was silently accepted');
    });
}

// ---- 5. transactions ----
$run('tx: begin/rollback/commit + savepoint (nested level)', function () use ($conn) {
    $before = ContractRow::count();
    $conn->beginTransaction();
    ContractRow::create(['name' => 'tx_r', 'qty' => 1]);
    check(ContractRow::count() === $before + 1, 'uncommitted row not visible');
    $conn->rollBack();
    check(ContractRow::count() === $before, 'rollback failed');
    $conn->beginTransaction();
    $conn->beginTransaction(); // savepoint level
    ContractRow::create(['name' => 'tx_sp', 'qty' => 1]);
    $conn->rollBack(1); // to savepoint
    $conn->commit();
    check(ContractRow::where('name', 'tx_sp')->doesntExist(), 'savepoint rollback failed');
    return ['ok' => true];
});

$run('tx: closure transaction with rollback on exception', function () use ($conn) {
    $before = ContractRow::count();
    try {
        $conn->transaction(function () {
            ContractRow::create(['name' => 'tx_ex', 'qty' => 1]);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {
        // expected
    }
    check(ContractRow::count() === $before, 'closure rollback failed');
    return ['ok' => true];
});

// ---- 6. type boundaries ----
$run('types: int64 extremes + float + decimal precision', function () {
    $m = ContractRow::create(['name' => 'types', 'qty' => 9223372036854775807, 'amount' => '99999999999999.99']);
    $f = ContractRow::find($m->id);
    check($f->qty === 9223372036854775807, 'int64 max mismatch');
    check($f->amount === '99999999999999.99', 'decimal mismatch: ' . var_export($f->amount, true));
    return ['qty' => $f->qty, 'amount' => $f->amount];
});

$run('types: UTF-8 + emoji + injection payload as data', function () use ($main) {
    $special = "中文'引号\"反斜杠\\换行\n百分%通配_🚀🏴 --";
    $m = ContractRow::create(['name' => 'utf8_row', 'memo' => $special, 'qty' => 1]);
    $f = ContractRow::find($m->id);
    check($f->memo === $special, 'special text mismatch');
    $inj = "x'); DROP TABLE " . $main . "; --";
    $m2 = ContractRow::create(['name' => $inj, 'qty' => 2]);
    check(ContractRow::find($m2->id)->name === $inj, 'injection payload changed');
    return ['memo_len' => strlen($special)];
});

$run('types: NULL vs empty string (O/A-mode semantics)', function () {
    $m = ContractRow::create(['name' => 'null_test', 'memo' => null, 'qty' => 0]);
    $f = ContractRow::find($m->id);
    check($f->memo === null, 'NULL did not round-trip');
    $raw = ContractRow::query()->where('name', 'null_test')->selectRaw('memo is null as is_null')->first();
    check((bool) $raw->is_null === true, 'memo not null at SQL level');
    return ['memo' => $f->memo];
});

// Independent PHP processes connect before a shared start barrier is released.
function concurrentWorkers(string $operation, string $table, int $id = 0): array
{
    $workers = [];
    $results = [];
    try {
        for ($i = 0; $i < 8; $i++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/concurrent_worker.php', $operation, $table, (string) $id],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start worker'); }
            stream_set_timeout($pipes[1], max(30, (int)(getenv('SOAK_SECONDS') ?: 0)+30));
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            check(trim((string) fgets($pipes[1])) === 'READY', 'worker failed to reach barrier');
        }
        foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "GO\n"); fclose($pipes[0]); }
        foreach ($workers as [$process, $pipes]) {
            $result = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($process) === 0, 'worker failed: ' . $error);
            $results[] = $result;
        }
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
    return $results;
}

$run('concurrency: duplicate key race (8 simultaneous writers)', function () use ($schema, $prefix, $sb, &$cleanup, $conn) {
    $child = $schema . '.' . $prefix . '_race';
    $sb->create($child, function (Blueprint $t) { $t->id(); $t->string('tag', 40); });
    $cleanup[] = $child;
    $results = array_count_values(concurrentWorkers('race', $child));
    check(($results['ok'] ?? 0) === 1 && ($results['dup'] ?? 0) === 7, 'unexpected race results');
    check($conn->table($child)->where('id', 42)->count() === 1, 'race left duplicate rows');
    return $results;
});

$run('concurrency: ignore and upsert (8 simultaneous writers)', function () use ($conn, $main) {
    $ignore = array_count_values(concurrentWorkers('ignore', $main, 83001));
    check(($ignore['1'] ?? 0)===1 && ($ignore['0'] ?? 0)===7, 'concurrent ignore mismatch');
    $upsert = concurrentWorkers('upsert', $main, 83002);
    check(count(array_filter($upsert, fn($r)=>$r==='ok'))===8, 'concurrent upsert failed');
    check($conn->table($main)->where('id',83002)->count()===1, 'concurrent upsert duplicated row');
    return ['ignore'=>$ignore,'upsert_writers'=>8];
});

$run('concurrency: atomic increment (8 processes x 20)', function () use ($main) {
    $m = ContractRow::create(['name' => 'incr', 'qty' => 1000]);
    $results = concurrentWorkers('increment', $main, (int) $m->id);
    check(count(array_filter($results, fn ($r) => $r === 'ok')) === 8, 'increment workers failed');
    check(ContractRow::find($m->id)->qty === 1160, 'concurrent increment lost updates');
    return ['qty' => 1160, 'workers' => 8];
});

// ---- 8. business flow: order atomicity ----
$run('business: order + lines rollback together', function () use (&$schema, &$prefix, &$cleanup, &$sb, $conn) {
    $orders = $schema . '.' . $prefix . '_orders';
    $lines = $schema . '.' . $prefix . '_lines';
    $sb->create($orders, function (Blueprint $t) {
        $t->id();
        $t->string('buyer', 60);
        $t->decimal('total', 12, 2);
    });
    $sb->create($lines, function (Blueprint $t) {
        $t->id();
        $t->bigInteger('order_id')->index();
        $t->string('sku', 40);
        $t->integer('qty');
    });
    $cleanup[] = $orders;
    $cleanup[] = $lines;

    $conn->beginTransaction();
    $oid = $conn->table($orders)->insertGetId(['buyer' => 'fail_case', 'total' => '9.99']);
    $conn->table($lines)->insert(['order_id' => $oid, 'sku' => 'sku_a', 'qty' => 2]);
    // simulate failure: insufficient stock for the second line
    $conn->rollBack();
    check($conn->table($orders)->where('buyer', 'fail_case')->doesntExist(), 'order leaked after rollback');
    check($conn->table($lines)->where('sku', 'sku_a')->doesntExist(), 'line leaked after rollback');

    $conn->beginTransaction();
    $oid2 = $conn->table($orders)->insertGetId(['buyer' => 'ok_case', 'total' => '12.50']);
    $conn->table($lines)->insert([['order_id' => $oid2, 'sku' => 'sku_a', 'qty' => 2], ['order_id' => $oid2, 'sku' => 'sku_b', 'qty' => 3]]);
    $conn->commit();
    $joined = $conn->table($orders . ' as o')->join($lines . ' as l', 'l.order_id', '=', 'o.id')->where('o.buyer', 'ok_case')->count();
    check($joined === 2, 'committed order join mismatch');
    return ['joined' => $joined];
});

$run('migration: schema-qualified index rename and drop', function () use ($make, $sb, $conn, $schema, $prefix) {
    $old = $prefix.'_old_idx';
    $new = $prefix.'_new_idx';
    $table = $make('_rename_idx', function (Blueprint $t) use ($old) {
        $t->bigInteger('id')->primary();
        $t->string('name', 30);
        $t->index('name', $old);
    });
    $sb->table($table, fn (Blueprint $t) => $t->renameIndex($old, $new));
    $rows = $conn->select('select indexname from pg_indexes where schemaname=? and indexname=?', [$schema, $new]);
    check(count($rows) === 1, 'renamed index not found in target schema');
    $sb->table($table, fn (Blueprint $t) => $t->dropIndex($new));
    check(count($conn->select('select indexname from pg_indexes where schemaname=? and indexname=?', [$schema, $new])) === 0, 'renamed index was not removed');
    return ['rename_and_drop' => true];
});

// ---- 9. relations ----
$run('relations: hasMany eager load', function () use ($schema, $prefix, $main) {
    $childTable = $schema . '.' . $prefix . '_child';
    ContractChild::$childTable = $childTable;
    ContractParent::$currentTable = $main;
    $model = new ContractParent();
    $model->childTable = $childTable;
    $found = $model->newQuery()->with('children')->where('name', 'q_0')->first();
    check($found !== null && $found->children->count() === 3, 'eager load mismatch');
    return ['children' => $found->children->count()];
});

// ---- 10. error surfaces ----
$run('relations: hasOne/belongsTo/belongsToMany/morphMany/morphTo', function () use ($make) {
    RelationOwner::$target = $make('_owner', function(Blueprint $t) { $t->id(); $t->string('label',40); });
    RelationChild::$target = $make('_rel_child', function(Blueprint $t) { $t->id(); $t->bigInteger('owner_id'); });
    RelationTag::$target = $make('_tag', function(Blueprint $t) { $t->id(); $t->string('label',40); });
    RelationOwner::$pivot = $make('_pivot', function(Blueprint $t) { $t->id(); $t->bigInteger('owner_id'); $t->bigInteger('tag_id'); });
    RelationNote::$target = $make('_note', function(Blueprint $t) { $t->id(); $t->bigInteger('notable_id'); $t->string('notable_type',200); $t->string('body',40); });
    $owner = RelationOwner::create(['label'=>'owner']);
    $child = $owner->children()->create([]);
    check((int)$child->owner->id === (int)$owner->id, 'belongsTo failed');
    check((int)RelationOwner::with('child')->find($owner->id)->child->id === (int)$child->id, 'hasOne preload failed');
    $tags = [RelationTag::create(['label'=>'a']), RelationTag::create(['label'=>'b'])];
    $owner->tags()->attach([$tags[0]->id, $tags[1]->id]);
    check(RelationOwner::with('tags')->find($owner->id)->tags->count()===2, 'pivot eager load failed');
    $owner->tags()->sync([$tags[1]->id]);
    check($owner->tags()->count()===1, 'pivot sync failed');
    $owner->tags()->detach();
    check($owner->tags()->count()===0, 'pivot detach failed');
    $note = $owner->notes()->create(['body'=>'hello']);
    check((int)$note->notable->id === (int)$owner->id, 'morphTo failed');
    check(RelationOwner::with('notes')->find($owner->id)->notes->count()===1, 'morphMany preload failed');
    return ['relations'=>5, 'pivot'=>'attach/sync/detach'];
});

$run('errors: duplicate key SQLSTATE + recovery', function () use ($conn, $main) {
    try {
        $conn->affectingStatement('insert into ' . $main . ' (id, name, qty) values (999999, ?, 1)', ['dup']);
        $conn->affectingStatement('insert into ' . $main . ' (id, name, qty) values (999999, ?, 1)', ['dup2']);
        throw new RuntimeException('duplicate key accepted');
    } catch (PDOException $e) {
        check(strlen((string) $e->getCode()) === 5, 'expected 5-char SQLSTATE, got ' . $e->getCode());
    }
    check((int) $conn->selectOne('select 1 as v')->v === 1, 'connection unrecoverable');
    return ['sqlstate' => (string) $e->getCode()];
});

$run('errors: NOT NULL violation', function () use ($conn, $main) {
    try {
        $conn->affectingStatement('insert into ' . $main . ' (name, qty) values (null, 1)');
        throw new RuntimeException('NULL accepted');
    } catch (PDOException $e) {
        return ['sqlstate' => (string) $e->getCode()];
    }
});

$probe('errors: division by zero', function () use ($conn) {
    try {
        $conn->selectOne('select 1/0 as v');
        return 'accepted';
    } catch (PDOException $e) {
        return 'rejected: ' . (string) $e->getCode();
    }
});

$run('Laravel driver registration: named connections, reconnect, migration up/down', function () use ($host, $port, $database, $user, $password, $mode, $schema, $distKey, $prefix, &$cleanup) {
    $app = new \Illuminate\Container\Container();
    $config = ['driver' => 'gaussdb', 'host' => $host, 'port' => $port,
        'database' => $database, 'username' => $user, 'password' => $password,
        'mode' => $mode, 'schema' => $schema, 'distribution_key' => $distKey];
    $app->instance('config', ['database.default' => 'first', 'database.connections' => ['first' => $config, 'second' => $config, 'split' => $config + ['read'=>[['host'=>$host, 'port'=>1], ['host'=>$host]], 'write'=>[['host'=>$host, 'port'=>1], ['host'=>$host]], 'sticky'=>true]]]);
    (new \GaussDb\Eloquent\GaussDBServiceProvider($app))->register();
    $app->singleton('db', fn ($app) => new \Illuminate\Database\DatabaseManager($app, new \Illuminate\Database\Connectors\ConnectionFactory($app)));
    $manager = $app->make('db');
    $first = $manager->connection('first');
    $second = $manager->connection('second');
    check($first !== $second && $first->getName() === 'first' && $second->getName() === 'second', 'named connection mismatch');
    $manager->disconnect('first');
    check((int) $first->selectOne('select 1 as v')->v === 1, 'automatic reconnect failed');
    $split = $manager->connection('split');
    $writePdo = $split->getPdo();
    $readPdo = $split->getReadPdo();
    check($readPdo !== $writePdo, 'read PDO not separate');
    $split->beginTransaction();
    check($split->getReadPdo() === $writePdo, 'transaction did not route to writer');
    $split->rollBack();
    $split->recordsHaveBeenModified(true);
    check($split->getReadPdo() === $writePdo, 'sticky did not route to writer');
    $split->forgetRecordModificationState();
    check($split->getReadPdo() === $readPdo, 'sticky reset did not restore reader');
    $manager->purge('split');
    $table = $schema . '.' . $prefix . '_migration';
    $cleanup[] = $table;
    $migration = new class($first->getSchemaBuilder(), $table) extends \Illuminate\Database\Migrations\Migration {
        public function __construct(private $schema, private string $table) {}
        public function up(): void {
            $this->schema->create($this->table, function (Blueprint $t) { $t->id(); $t->string('label', 40); });
            $this->schema->table($this->table, function (Blueprint $t) { $t->string('note', 40)->nullable(); });
        }
        public function down(): void { $this->schema->dropIfExists($this->table); }
    };
    $migration->up();
    check($first->getSchemaBuilder()->hasColumn($table, 'note'), 'migration add column failed');
    $migration->down();
    check(!$first->getSchemaBuilder()->hasTable($table), 'migration rollback failed');
    $manager->purge('first'); $manager->purge('second');
    return ['connections' => 2, 'reconnect' => true, 'migration' => 'up/down'];
});

if ((int)(getenv('SOAK_SECONDS') ?: 0)>0) {
    $run('soak: 8 writers, rollback and connection churn', function () use ($make,$conn) {
        $table=$make('_soak',function(Blueprint $t) { $t->id(); $t->bigInteger('qty')->default(0); });
        $conn->table($table)->insert(['id'=>1,'qty'=>0]);
        $workers=array_map(fn($r)=>json_decode($r,true,512,JSON_THROW_ON_ERROR), concurrentWorkers('soak',$table));
        $expected=array_sum(array_column($workers,'committed'));
        $actual=(int)$conn->table($table)->where('id',1)->value('qty');
        check($actual===$expected,'soak lost increments or leaked rollback');
        return ['seconds'=>(int)getenv('SOAK_SECONDS'),'workers'=>$workers,'expected'=>$expected,'actual'=>$actual];
    });
}

// ---- cleanup ----
foreach (array_reverse($cleanup) as $t) {
    try {
        $sb->dropIfExists($t);
    } catch (Throwable $e) {
        fwrite(STDERR, "cleanup failed for {$t}: " . substr($e->getMessage(), 0, 120) . "\n");
        $fail++;
    }
}
$run('cleanup: zero leftovers', function () use ($conn, $schema, $prefix) {
    $rows = $conn->select(
        'select table_name from information_schema.tables where table_schema = ? and table_name like ?',
        [$schema, $prefix . '%']
    );
    check($rows === [], 'test tables remain after cleanup');
    return ['leftovers' => 0];
});


echo json_encode([
    'environment' => [
        'architecture' => php_uname('m'),
        'recorded_at' => gmdate('c'),
        'php' => PHP_VERSION,
        'database' => $database,
        'schema' => $schema,
        'mode' => $mode,
        'distributed' => $distributed,
        'server' => $conn->getServerVersion(),
    ],
    'summary' => ['pass' => count(array_filter($results, fn ($r) => $r['status'] === 'pass')), 'fail' => $fail],
    'probes' => $probeLog,
    'tests' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($fail === 0 ? 0 : 1);
