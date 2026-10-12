<?php
require dirname(__DIR__).'/vendor/autoload.php';
use GaussDb\Eloquent\GaussDBServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connectors\ConnectionFactory;
function checkHA($v,$message) { if (!$v) throw new RuntimeException($message); }
$app=new Container;
$config=['driver'=>'gaussdb','host'=>'127.0.0.1','port'=>55562,'database'=>'xorm_ha','username'=>'xorm_ha_user',
    'password'=>getenv('HA_PASSWORD'),'mode'=>'A','schema'=>'public','sslmode'=>'disable',
    'write'=>[['port'=>55572],['port'=>55562]],'read'=>[['port'=>55572],['port'=>55562]],'sticky'=>true];
$app->instance('config',['database.default'=>'ha','database.connections'=>['ha'=>$config,'idle'=>$config]]);
(new GaussDBServiceProvider($app))->register();
$app->singleton('db',fn($app)=>new DatabaseManager($app,new ConnectionFactory($app)));
$db=$app->make('db');$idle=$db->connection('idle');$idle->selectFromWriteConnection('select 1');$c=$db->connection();$table='public.'.getenv('HA_TABLE');
$c->getSchemaBuilder()->create($table,function($t){$t->id();$t->string('marker',40);});
$c->table($table)->insert(['id'=>1,'marker'=>'before_switch']);
$c->forgetRecordModificationState();
$recovery=$c->getReadPdo()->query('select pg_is_in_recovery()')->fetchColumn();
checkHA(!in_array($recovery,[true,'t','1',1],true),'reader selected unsupported hot standby');
$c->beginTransaction();$c->table($table)->insert(['id'=>2,'marker'=>'uncommitted_switch']);
echo "SWITCH_READY\n";flush();checkHA(trim(fgets(STDIN))==='GO','missing switch barrier');
$failed=false;try{$c->commit();}catch(Throwable $e){$failed=true;}
checkHA($failed,'commit on old primary unexpectedly succeeded');
checkHA((int)$idle->selectFromWriteConnection('select inet_server_port() as p')[0]->p===55572,'idle query did not automatically reconnect');
$db->purge('ha');$c=$db->connection();
checkHA((int)$c->selectFromWriteConnection('select inet_server_port() as p')[0]->p===55572,'did not select new primary');
checkHA($c->table($table)->where('id',1)->count()===1,'committed marker missing');
checkHA($c->table($table)->where('id',2)->count()===0,'uncommitted switch transaction replayed');
$c->table($table)->insert(['id'=>3,'marker'=>'before_loss']);
$c->beginTransaction();$c->table($table)->insert(['id'=>4,'marker'=>'uncommitted_loss']);
echo "LOSS_READY\n";flush();checkHA(trim(fgets(STDIN))==='GO','missing loss barrier');
$failed=false;try{$c->commit();}catch(Throwable $e){$failed=true;}
checkHA($failed,'commit on lost primary unexpectedly succeeded');
$db->purge('ha');$c=$db->connection();
checkHA((int)$c->selectFromWriteConnection('select inet_server_port() as p')[0]->p===55562,'did not fail over');
checkHA($c->table($table)->where('id',3)->count()===1,'committed loss marker missing');
checkHA($c->table($table)->where('id',4)->count()===0,'uncommitted loss transaction replayed');
$c->table($table)->insert(['id'=>5,'marker'=>'after_loss']);
checkHA($c->table($table)->count()===3,'unexpected rows after failover');
$c->getSchemaBuilder()->drop($table);
echo json_encode(['pass'=>true,'committed_preserved'=>true,'uncommitted_not_replayed'=>true,'write_after_failover'=>true,'idle_query_reconnected'=>true,'read_endpoint_fallback'=>true]),"\n";
