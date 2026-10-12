"""Validate saved acceptance evidence and emit a compact machine-readable summary."""
from datetime import datetime, timezone
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
reports = root / 'reports'
summary = {'generated_at': datetime.now(timezone.utc).isoformat(), 'contract': [], 'tls': [], 'ha': [], 'artisan': [], 'soak': []}
for topology in ('central','dist'):
    for arch in ('arm64','x86_64'):
        unit = f'{topology}-{arch}'
        data = json.loads((reports/f'contract-{unit}.json').read_text())
        assert data['summary']['fail'] == 0, unit
        summary['contract'].append({'unit':unit, **data['summary']})
        artisan = json.loads((reports/f'artisan-{unit}.json').read_text())
        assert len(artisan)==6 and all(r['exit']==0 for r in artisan), unit
        summary['artisan'].append({'unit':unit,'commands':5,'cleanup':True})
        soak = json.loads((reports/f'soak-{unit}.json').read_text())
        assert soak['summary']['fail']==0, unit
        detail = next(t['detail'] for t in soak['tests'] if t['name'].startswith('soak:'))
        assert detail['actual']==detail['expected'] and len(detail['workers'])==8, unit
        summary['soak'].append({'unit':unit,'seconds':detail['seconds'],'writers':8,
            'committed':detail['actual'], 'rolled_back':sum(w['rolled_back'] for w in detail['workers']),
            'connections':sum(w['connections'] for w in detail['workers']),
            'max_worker_peak_bytes':max(w['peak_bytes'] for w in detail['workers'])})
for arch in ('arm64','x86_64'):
    for stage in ('on','off'):
        data=json.loads((reports/f'tls-{stage}-{arch}.json').read_text())
        assert all(r['pass'] for r in data), (stage,arch)
        summary['tls'].append({'architecture':arch,'stage':stage,'pass':len(data)})
    ha=json.loads((reports/f'ha-{arch}.json').read_text())
    assert ha['pass'] and ha['roles']=={'55562':'f','55572':'t'}, arch
    summary['ha'].append({'architecture':arch,'pass':True,'roles_restored':True,
        'planned_switchover':True,'primary_loss':True,'uncommitted_not_replayed':True})
assert json.loads((reports/'tls-restoration.json').read_text())['restored']
summary['pending']=['physical multi-CN switchover','multi-hour soak','mutual TLS client authentication',
                    'physical hot-standby reads with the tested official ODBC driver',
                    'all remaining Eloquent relationship types and arbitrary destructive migrations']
summary['execution_note']='x86_64 client runs under Docker architecture emulation on an arm64 host; native x86 hardware is not certified.'
(reports/'acceptance.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
print('PASS: contract',sum(r['pass'] for r in summary['contract']),
      'TLS',sum(r['pass'] for r in summary['tls']), 'HA architectures',len(summary['ha']),
      'Artisan commands',sum(r['commands'] for r in summary['artisan']),
      'soak increments',sum(r['committed'] for r in summary['soak']))
