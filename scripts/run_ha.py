"""Planned switchover and abrupt primary loss on PHP's isolated pair."""
import json
import os
import subprocess
import time
import uuid
import select
from setup_ha import ROOT,NAME,HOME,db

def sql(port,statement):
    return db([HOME+'/bin/gsql','-p',str(port),'-d','xorm_ha','-t','-A','-v','ON_ERROR_STOP=1'],statement.encode()).stdout.decode().strip()
def stopped(node):
    data='/opt/gaussdb/eloquent-'+node
    db([HOME+'/bin/gs_ctl','stop','-D',data,'-m','immediate','-t','3'],check=False)
    for _ in range(50):
        if db(['test','!','-f',data+'/postmaster.pid'],check=False).returncode==0:return
        time.sleep(.1)
    raise RuntimeError('Stop timeout')
def build():
    db([HOME+'/bin/gs_ctl','build','-D','/opt/gaussdb/eloquent-standby','-b','full','-M','standby','-t','60'],timeout=100)
def switch(node):
    db([HOME+'/bin/gs_ctl','switchover','-D','/opt/gaussdb/eloquent-'+node,'-m','fast','-t','30'],timeout=50)

for arch in ('arm64','x86_64'):
    env=os.environ.copy();env['HA_PASSWORD']=(ROOT/'.private/ha.env').read_text().strip().split('=',1)[1]
    name='elq_ha_'+uuid.uuid4().hex[:10];env['HA_TABLE']=name
    log=[];passed=False
    cmd=['docker','run','--rm','-i','--platform','linux/arm64' if arch=='arm64' else 'linux/amd64',
        '--network','container:'+NAME,'-v',str(ROOT)+':/workspace:ro','-w','/workspace',
        '-e','HA_PASSWORD','-e','HA_TABLE','gaussdb-eloquent:8.3-'+arch,'php','tests/ha.php']
    p=subprocess.Popen(cmd,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
    try:
        for stage in ('SWITCH_READY','LOSS_READY'):
            while True:
                ready,_,_=select.select([p.stdout],[],[],60)
                if not ready:raise RuntimeError('HA worker stage timeout')
                line=p.stdout.readline();log.append(line)
                if line.strip()==stage:break
                if not line:raise RuntimeError('HA worker exited before '+stage)
            if stage=='SWITCH_READY':switch('standby')
            else:
                deadline=time.monotonic()+15
                while sql(55562,'select count(*) from public.'+name+' where id=3')!='1':
                    if time.monotonic()>deadline:raise RuntimeError('Replica marker not applied')
                    time.sleep(.1)
                stopped('standby')
                db([HOME+'/bin/gs_ctl','failover','-D','/opt/gaussdb/eloquent-primary','-t','30'],timeout=50)
                build()
            p.stdin.write('GO\n');p.stdin.flush()
        log.append(p.communicate(timeout=40)[0]);passed=p.returncode==0
    finally:
        if p.poll() is None:p.terminate();p.wait(timeout=10)
        if sql(55562,'select pg_is_in_recovery()')=='t':switch('primary')
        standby=db([HOME+'/bin/gsql','-p','55572','-d','xorm_ha','-t','-A','-c','select pg_is_in_recovery()'],check=False)
        if standby.returncode or standby.stdout.decode().strip()!='t':stopped('standby');build()
        sql(55562,'drop table if exists public.'+name)
        states={str(port):sql(port,'select pg_is_in_recovery()') for port in (55562,55572)}
        report={'architecture':arch,'pass':passed,'roles':states,'output':''.join(log)}
        (ROOT/'reports'/f'ha-{arch}.json').write_text(json.dumps(report,indent=2))
        print(arch,'HA',passed,'restored',states,flush=True)
        if states!={'55562':'f','55572':'t'}:raise RuntimeError('Roles not restored')
    if not passed:raise SystemExit(1)
