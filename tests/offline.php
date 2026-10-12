<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use GaussDb\Eloquent\ConnectionConfig;
use GaussDb\Eloquent\GaussDBConnection;
use Illuminate\Database\Query\Expression;

function expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $fn, string $class): void {
    try { $fn(); } catch (Throwable $e) { expect($e instanceof $class, get_class($e)); return; }
    throw new RuntimeException('Expected rejection');
}
$c = new GaussDBConnection(new PDO('sqlite::memory:'));
$q = $c->table('public.demo');
$g = $c->getQueryGrammar();
$sql = $g->compileUpsert($q, [['id'=>1,'qty'=>2],['id'=>2,'qty'=>3]], ['id'], ['qty']);
expect(substr_count($sql, '?') === 4 && str_contains($sql, 'union all'), 'batch binding mismatch');
$sql = $g->compileUpsert($q, [['id'=>1,'qty'=>new Expression('10')]], ['id'], ['qty'=>new Expression('20')]);
expect(substr_count($sql, '?') === 1 && str_contains($sql, '"qty" = 20'), 'raw expressions consume bindings');
rejects(fn () => $g->compileUpsert($q, [['id'=>1]], ['missing'], []), InvalidArgumentException::class);
rejects(fn () => $g->compileUpsert($q, [['id'=>1]], [], []), InvalidArgumentException::class);
rejects(fn () => $g->compileInsertOrIgnore($q, [['id'=>1]]), LogicException::class);
rejects(fn () => $g->compileInsertOrIgnoreUsing($q, ['id'], 'select 1'), LogicException::class);
expect(str_contains($q->lockForUpdate()->limit(1)->toSql(), 'for update'), 'central lock lost');
$cfg = new ConnectionConfig('localhost', 5432, 'demo', 'user', '', 'ORA', 'public', 'id');
$d = new GaussDBConnection(new PDO('sqlite::memory:'), 'demo', '', [], $cfg);
rejects(fn () => $d->table('demo')->lockForUpdate()->limit(1)->toSql(), LogicException::class);
rejects(fn () => $d->table('demo')->sharedLock()->offset(1)->toSql(), LogicException::class);
expect(str_contains($d->table('demo')->where('id', 1)->lockForUpdate()->toSql(), 'for update'), 'distributed lock lost');
rejects(fn () => $d->lastInsertId('public.demo_id_seq'), LogicException::class);
$sqlite = new GaussDBConnection(new PDO('sqlite::memory:'), '', '', ['gaussdb_ignore_using_max_rows'=>1]);
$sqlite->statement('create table demo (id integer primary key, name text not null unique)');
expect($sqlite->table('demo')->insertOrIgnore([['id'=>1,'name'=>'a'],['id'=>3,'name'=>'b']]) === 2, 'batch insert count');
// Only GaussDB's precise 23505 state is ignored; unrelated integrity states must propagate.
rejects(fn () => $sqlite->table('demo')->insertOrIgnore(['id'=>2,'name'=>'a']), Illuminate\Database\QueryException::class);
rejects(fn () => $sqlite->table('demo')->insertOrIgnoreUsing(['id','name'], $sqlite->table('demo')->select('id','name')), LengthException::class);
expect($sqlite->table('demo')->count()===2, 'source cap changed target');
$tlsConfig=new ConnectionConfig('localhost',5432,'demo','user','','A',sslMode:'verify-full',sslRootCert:'/certs/root.crt',targetSessionAttrs:'cluster-mainnode');
expect(str_contains($tlsConfig->dsn(),"sslrootcert='/certs/root.crt'") && str_contains($tlsConfig->dsn(),'TargetServerType=cluster-mainnode'), 'TLS or standby options missing');
rejects(fn()=>new ConnectionConfig('localhost',5432,'demo','user','','A',sslMode:'invalid'), InvalidArgumentException::class);
rejects(fn()=>new ConnectionConfig('localhost',5432,'demo','user','','A',targetSessionAttrs:'invalid'), InvalidArgumentException::class);
rejects(fn()=>(new ConnectionConfig('localhost',5432,'demo','user','','A',sslRootCert:'/cert;inject'))->dsn(), InvalidArgumentException::class);
$c->useDefaultSchemaGrammar();
$renameSql = (new GaussDb\Eloquent\GaussDBSchemaGrammar($c))->compileRenameIndex(
    new Illuminate\Database\Schema\Blueprint($c, 'business.demo'),
    new Illuminate\Support\Fluent(['from'=>'old_index', 'to'=>'new_index'])
);
expect($renameSql === 'alter index "business"."old_index" rename to "new_index"', 'index rename schema missing');
echo "offline regression checks PASS\n";
