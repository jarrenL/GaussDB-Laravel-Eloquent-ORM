"""TLS verification on the disposable PHP HA pair; exact configuration restored."""
import json
import os
from pathlib import Path
import subprocess
import time
from setup_ha import ROOT, NAME, HOME, db

certs=ROOT/'.private/certs'
certs.mkdir(parents=True,exist_ok=True,mode=0o700)
for label,san in [('trusted','IP:127.0.0.1,DNS:localhost'),('unrelated','DNS:unrelated.invalid')]:
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(certs/(label+'.key')),
        '-out',str(certs/(label+'.crt')),'-days','30','-subj','/CN='+('127.0.0.1' if label=='trusted' else 'unrelated.invalid'),'-addext','subjectAltName='+san],
        check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    (certs/(label+'.key')).chmod(0o600)
    (certs/(label+'.crt')).chmod(0o600)
password=(ROOT/'.private/ha.env').read_text().strip().split('=',1)[1]
env=os.environ.copy();env['HA_PASSWORD']=password
nodes=[('primary','primary'),('standby','standby')]
backups={}
def stop(node):
    data='/opt/gaussdb/eloquent-'+node
    db([HOME+'/bin/gs_ctl','stop','-D',data,'-m','fast','-t','3'],check=False)
    for _ in range(50):
        if db(['test','!','-f',data+'/postmaster.pid'],check=False).returncode==0:return
        time.sleep(.1)
    raise RuntimeError('TLS lab stop timeout')
def start(node,role):
    data='/opt/gaussdb/eloquent-'+node
    db([HOME+'/bin/gs_ctl','start','-D',data,'-M',role,'-l',data+'/tls-start.log','-t','30'])
def probe(stage,arch):
    current=env.copy();current['TLS_STAGE']=stage
    p=subprocess.run(['docker','run','--rm','--platform','linux/arm64' if arch=='arm64' else 'linux/amd64',
        '--network','container:'+NAME,'-v',str(ROOT)+':/workspace:ro','-v',str(certs)+':/certs:ro','-w','/workspace',
        '-e','HA_PASSWORD','-e','TLS_STAGE','gaussdb-eloquent:8.3-'+arch,'php','tests/tls.php'],env=current,capture_output=True,text=True,timeout=90)
    (ROOT/'reports'/f'tls-{stage}-{arch}.txt').write_text(p.stdout.replace(password,'[redacted]')+p.stderr.replace(password,'[redacted]'))
    start=p.stdout.find('[\n')
    if start<0: raise RuntimeError('TLS suite did not return JSON; see diagnostic report')
    report,_=json.JSONDecoder().raw_decode(p.stdout[start:])
    (ROOT/'reports'/f'tls-{stage}-{arch}.json').write_text(json.dumps(report,indent=2))
    print(stage,arch,'pass',sum(r['pass'] for r in report),'/',len(report),flush=True)
    return p.returncode==0
passed=True
try:
    for node,role in nodes:
        data='/opt/gaussdb/eloquent-'+node
        backups[node]=db(['cat',data+'/gaussdb.conf']).stdout
        for suffix in ('crt','key'):
            subprocess.run(['docker','cp',str(certs/('trusted.'+suffix)),NAME+':'+data+'/elq-tls.'+suffix],check=True,stdout=subprocess.DEVNULL)
        subprocess.run(['docker','exec','-u','root',NAME,'sh','-c',f'chown gausscore:dbgrp {data}/elq-tls.*; chmod 600 {data}/elq-tls.*'],check=True)
        replacement=backups[node]+f"\nssl=on\nssl_cert_file='{data}/elq-tls.crt'\nssl_key_file='{data}/elq-tls.key'\n".encode()
        db(['sh','-c','cat > '+data+'/gaussdb.conf'],replacement)
    for node,_ in reversed(nodes):stop(node)
    for node,role in nodes:start(node,role)
    for arch in ('arm64','x86_64'):passed=probe('on',arch) and passed
finally:
    for node,content in backups.items():db(['sh','-c','cat > /opt/gaussdb/eloquent-'+node+'/gaussdb.conf'],content)
    for node,_ in reversed(nodes):stop(node)
    for node,role in nodes:start(node,role)
    restored=all(db(['cat','/opt/gaussdb/eloquent-'+n+'/gaussdb.conf']).stdout==v for n,v in backups.items())
    (ROOT/'reports/tls-restoration.json').write_text(json.dumps({'restored':restored,'container':NAME}))
    if not restored:raise RuntimeError('TLS configuration restoration failed')
    for node,_ in nodes:
        db(['rm','-f','/opt/gaussdb/eloquent-'+node+'/elq-tls.crt','/opt/gaussdb/eloquent-'+node+'/elq-tls.key'])
for arch in ('arm64','x86_64'):passed=probe('off',arch) and passed
raise SystemExit(0 if passed else 1)
