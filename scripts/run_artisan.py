"""Exercise real Laravel console commands against disposable tables only."""
import json
import os
from pathlib import Path
import subprocess
import uuid
from run_matrix import ROOT, base

failed = False
for topology in ('central', 'dist'):
    for arch in ('arm64', 'x86_64'):
        dist = topology == 'dist'
        prefix = 'DIST' if dist else 'CENTRAL'
        schema = base.get(prefix + '_SCHEMA', 'tortoise_test' if dist else 'public')
        name = 'elq_art_' + uuid.uuid4().hex[:10]
        env = base.copy()
        env.update(GAUSS_HOST=base.get(prefix + '_DOCKER_HOST','host.docker.internal'),
            GAUSS_PORT=base.get(prefix+'_PORT','55432' if dist else '55452'),
            GAUSS_DATABASE=base.get(prefix+'_DATABASE','distributed_acceptance' if dist else 'tortoise_central'),
            GAUSS_USER=base.get(prefix+'_USER','tortoise_lab'),
            GAUSS_PASSWORD=base.get(prefix+'_PASSWORD',base.get('CENTRAL_PASSWORD','')),
            GAUSS_SCHEMA=schema, GAUSS_MODE='ORA' if dist else 'A', GAUSS_DISTRIBUTED='true' if dist else 'false',
            GAUSS_ARTISAN_TABLE=name, GAUSS_MIGRATION_TABLE=schema+'.'+name+'_migrations')
        command=['docker','run','--rm','--platform','linux/amd64' if arch=='x86_64' else 'linux/arm64',
            '--add-host','host.docker.internal:host-gateway','-v',str(ROOT)+':/workspace', '-w','/workspace/tests/laravel']
        for key in env:
            if key.startswith('GAUSS_'): command+=['-e',key]
        command+=['gaussdb-eloquent:8.3-'+arch]
        results=[]
        for args in (['migrate','--force'],['migrate:status'],['migrate:rollback','--step=1','--force'],
                     ['migrate','--force'],['migrate:rollback','--force']):
            p=subprocess.run(command+['php','artisan',*args,'--no-interaction','--no-ansi'],env=env,capture_output=True,text=True,timeout=90)
            results.append({'command':' '.join(args),'exit':p.returncode,'output':p.stdout+p.stderr})
            if p.returncode: failed=True; break
        # Always remove the dedicated migration repository and any fixture table.
        p=subprocess.run(command+['php','cleanup.php'],env=env,capture_output=True,text=True,timeout=60)
        results.append({'command':'cleanup','exit':p.returncode,'output':p.stdout+p.stderr})
        failed |= p.returncode != 0
        (ROOT/'reports'/f'artisan-{topology}-{arch}.json').write_text(json.dumps(results,indent=2))
        print(topology,arch,[(r['command'],r['exit']) for r in results])
raise SystemExit(1 if failed else 0)
