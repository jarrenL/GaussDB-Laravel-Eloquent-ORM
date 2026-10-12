<?php
require dirname(__DIR__).'/vendor/autoload.php';
use GaussDb\Eloquent\ConnectionConfig;
use GaussDb\Eloquent\Connector;
$results=[];
$off=getenv('TLS_STAGE')==='off';
$cases=$off ? [['require','127.0.0.1','',false]] : [
    ['require','127.0.0.1','',true],
    ['verify-ca','127.0.0.1','/certs/trusted.crt',true],
    ['verify-full','127.0.0.1','/certs/trusted.crt',true],
    ['verify-full','127.0.0.1','/certs/unrelated.crt',false],
    ['verify-full','127.0.0.2','/certs/trusted.crt',false],
    ['verify-ca','127.0.0.2','/certs/trusted.crt',true],
];
foreach (($off ? [55562,55572] : [55562]) as $port) {
    foreach ($cases as [$mode,$host,$ca,$expected]) {
        $accepted=false; $state=null; $error=null;
        try {
            $pdo=(new Connector(new ConnectionConfig($host,$port,'xorm_ha','xorm_ha_user',getenv('HA_PASSWORD'),'A',sslMode:$mode,sslRootCert:$ca,targetSessionAttrs:$port===55572?'cluster-mainnode':'cluster-primary')))->connect();
            $accepted=(int)$pdo->query('select 1')->fetchColumn()===1;
        } catch (PDOException $e) { $state=$e->errorInfo[0] ?? (string)$e->getCode(); if (preg_match('/SQLSTATE\[([^]]+)\]/', $e->getMessage(), $match)) { $state=$match[1]; } $error=str_replace(getenv('HA_PASSWORD'), '[redacted]', $e->getMessage()); }
        $rejectionValid = $expected || ($off ? preg_match('/SSL.*required|does not support SSL/i', $error ?? '') : ($host==='127.0.0.2' ? preg_match('/certificate.*(match|host)|host.*certificate|common name.*not match host/i',$error ?? '') : preg_match('/certificate.*verif|self.signed|unknown ca/i',$error ?? '')));
        $results[]=['port'=>$port,'mode'=>$mode,'host'=>$host,'ca'=>basename($ca),'expected'=>$expected,'accepted'=>$accepted,'state'=>$state,'error'=>$error,'pass'=>$accepted===$expected && (bool)$rejectionValid];
    }
}
echo json_encode($results,JSON_PRETTY_PRINT),"\n";
exit(count(array_filter($results,fn($r)=>!$r['pass'])) ? 1 : 0);
