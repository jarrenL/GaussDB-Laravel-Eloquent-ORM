#!/usr/bin/env python3
"""Create an isolated 507 primary/standby pair. Never mutate the existing labs."""
import pathlib, subprocess, secrets, json, os, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
NAME='gaussdb-eloquent-ha-lab';HOME='/opt/gaussdb/ha-app'
def run(args,input=None,timeout=90,check=True):
    p=subprocess.run(args,input=input,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=timeout)
    if check and p.returncode:raise RuntimeError(p.stdout.decode(errors='replace')[-3000:])
    return p

def db(args,input=None,timeout=90,check=True):
    return run(['docker','exec','-i','-e',f'GAUSSHOME={HOME}','-e',f'LD_LIBRARY_PATH={HOME}/lib','-e','GAUSSLOG=/opt/gaussdb',NAME]+args,input,timeout,check)
def write(path,text):return db(['sh','-c','umask 077; cat > '+path],text.encode())
def main():
    state=ROOT/'.private';state.mkdir(mode=0o700,exist_ok=True)
    exists=run(['docker','inspect',NAME],check=False)
    if exists.returncode:
        run(['docker','create','--name',NAME,'--user','gausscore','--network','none','gaussdb507-arm64:507.0.0','sleep','infinity'])
        run(['docker','start',NAME])
        with tempfile.TemporaryDirectory(prefix='eloquent-ha-kernel-') as tmp:
            run(['docker','cp','gaussdb-507-ha-lab:'+HOME,tmp+'/ha-app'],timeout=180)
            run(['docker','cp',tmp+'/ha-app',NAME+':'+HOME],timeout=180)
        run(['docker','exec','-u','root',NAME,'chown','-R','gausscore:dbgrp',HOME])
        run(['docker','exec','-u','root',NAME,'chown','gausscore:dbgrp','/opt/gaussdb'])
    if db(['test','-d','/opt/gaussdb/eloquent-primary'],check=False).returncode==0:
        raise SystemExit('HA lab already initialized; use run_ha.py, or remove only this disposable lab before setup')
    password='Xorm!'+secrets.token_hex(12)
    write('/tmp/xorm-admin.pw','Init!'+secrets.token_hex(12)+'\n')
    for node,port,remote in [('primary',55562,55572),('standby',55572,55562)]:
        data='/opt/gaussdb/eloquent-'+node
        result=db([HOME+'/bin/gs_initdb','-D',data,'--nodename=xorm_'+node,'--pwfile=/tmp/xorm-admin.pw','--encoding=UTF8','--locale=C'],timeout=120)
        (ROOT/'reports'/f'ha-init-{node}.txt').write_bytes(result.stdout)
        additions=f"""
port={port}
listen_addresses='*'
max_connections=100
shared_buffers='128MB'
wal_level=hot_standby
max_wal_senders=8
hot_standby=on
ssl=off
remote_read_mode=off
replconninfo1='localhost=127.0.0.1 localport={port+1} localheartbeatport={port+3} localservice={port+2} remotehost=127.0.0.1 remoteport={remote+1} remoteheartbeatport={remote+3} remoteservice={remote+2}'
"""
        db(['sh','-c','cat >> '+data+'/gaussdb.conf'],additions.encode())
        write(data+'/gs_hba.conf',"local all all trust\nhost all all 127.0.0.1/32 trust\nhost replication gausscore 127.0.0.1/32 trust\nhost all all 0.0.0.0/0 sha256\n")
    result=db([HOME+'/bin/gs_ctl','start','-D','/opt/gaussdb/eloquent-primary','-M','primary','-l','/opt/gaussdb/eloquent-primary/start.log','-t','30'])
    (ROOT/'reports/ha-primary-start.txt').write_bytes(result.stdout)
    result=db([HOME+'/bin/gs_ctl','build','-D','/opt/gaussdb/eloquent-standby','-b','full','-M','standby','-t','60'],timeout=100)
    (ROOT/'reports/ha-build.txt').write_bytes(result.stdout)
    sql="ALTER ROLE gausscore PASSWORD '"+password+"'; CREATE DATABASE xorm_ha DBCOMPATIBILITY 'A';\n"
    db([HOME+'/bin/gsql','-p','55562','-d','postgres','-v','ON_ERROR_STOP=1'],sql.encode())
    sql="CREATE USER xorm_ha_user PASSWORD '"+password+"'; ALTER SCHEMA public OWNER TO xorm_ha_user;\n"
    p=db([HOME+'/bin/gsql','-p','55562','-d','xorm_ha','-v','ON_ERROR_STOP=1'],sql.encode())
    # Do not persist SQL echo output containing the password.
    env=state/'ha.env';env.touch(mode=0o600,exist_ok=True)
    env.write_text('HA_PASSWORD='+password+'\n')
    db(['rm','-f','/tmp/xorm-admin.pw'])
    print('Isolated 507 primary/standby created; credentials saved privately.',flush=True)
if __name__=='__main__':main()
