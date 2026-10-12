"""Run isolated contract suites; credentials are passed via inherited environment."""
import concurrent.futures
import json
import os
from pathlib import Path
import shlex
import subprocess

ROOT = Path(__file__).resolve().parents[1]
base = os.environ.copy()
config = base.get('GAUSSDB_TEST_ENV_FILE')
if config:
    for line in Path(config).read_text().splitlines():
        if '=' in line and not line.lstrip().startswith('#'):
            key, value = line.split('=', 1)
            base.setdefault(key.strip(), shlex.split(value)[0] if value.strip() else '')


def run(unit):
    topology, arch = unit
    dist = topology == 'dist'
    prefix = 'DIST' if dist else 'CENTRAL'
    env = base.copy()
    env.update(
        GAUSS_HOST=base.get(prefix + '_DOCKER_HOST', 'host.docker.internal'),
        GAUSS_PORT=base.get(prefix + '_PORT', '55432' if dist else '55452'),
        GAUSS_DATABASE=base.get(prefix + '_DATABASE', 'distributed_acceptance' if dist else 'tortoise_central'),
        GAUSS_USER=base.get(prefix + '_USER', 'tortoise_lab'),
        GAUSS_PASSWORD=base.get(prefix + '_PASSWORD', base.get('CENTRAL_PASSWORD', '')),
        GAUSS_MODE='ORA' if dist else 'A',
        GAUSS_SCHEMA=base.get(prefix + '_SCHEMA', 'tortoise_test' if dist else 'public'),
        GAUSS_DISTRIBUTED='true' if dist else 'false',
    )
    command = ['docker', 'run', '--rm', '--platform', 'linux/amd64' if arch == 'x86_64' else 'linux/arm64',
               '--add-host', 'host.docker.internal:host-gateway', '-v', str(ROOT) + ':/workspace:ro', '-w', '/workspace']
    for key in env:
        if key.startswith('GAUSS_'):
            command += ['-e', key]
    if 'SOAK_SECONDS' in env: command += ['-e', 'SOAK_SECONDS']
    result = subprocess.run(command + ['gaussdb-eloquent:8.3-' + arch, 'php', 'tests/contract.php'],
                            env=env, capture_output=True, text=True, timeout=max(180,int(base.get('SOAK_SECONDS','0'))+120))
    try:
        report = json.loads(result.stdout)
    except ValueError:
        # Avoid emitting database exception text containing connection details.
        raise RuntimeError(f'{topology}/{arch}: suite did not return JSON') from None
    (ROOT / 'reports' / f"{'soak' if int(base.get('SOAK_SECONDS','0')) else 'contract'}-{topology}-{arch}.json").write_text(result.stdout)
    print(topology, arch, report['summary'])
    for test in report['tests']:
        if test['status'] == 'fail':
            print('FAILED:', test['name'], test['detail']['sqlstate'])
    return result.returncode == 0 and report['summary']['fail'] == 0


if __name__ == '__main__':
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results = list(pool.map(run, [(t, a) for t in ('central', 'dist') for a in ('arm64', 'x86_64')]))
    raise SystemExit(0 if all(results) else 1)
