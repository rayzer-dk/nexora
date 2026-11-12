#!/usr/bin/env python3
"""Install vendor/ exactly as composer.lock says, using anonymous git fetches.

Used when GitHub zip archives (api.github.com/zipball, codeload) are blocked, so `composer install`
cannot download dist files. Only packages whose installed version/reference differs are fetched.
"""
import json, os, re, shutil, subprocess, sys, tempfile
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
os.chdir(ROOT)
with_dev = '--no-dev' not in sys.argv
lock = json.load(open('composer.lock'))
want = {p['name']: p for p in lock['packages'] + (lock['packages-dev'] if with_dev else [])}
dev = {p['name'] for p in lock['packages-dev']}
os.makedirs('vendor/composer', exist_ok=True)
try:
    have = {p['name']: p for p in json.load(open('vendor/composer/installed.json'))['packages']}
except (OSError, ValueError, KeyError):
    have = {}
ref = lambda p: (p.get('source') or p.get('dist') or {}).get('reference')
todo = [n for n, p in want.items() if n not in have or have[n]['version'] != p['version'] or ref(have[n]) != ref(p) or not os.path.isdir('vendor/' + n)]
for n in set(have) - set(want):
    shutil.rmtree('vendor/' + n, ignore_errors=True)

def git_source(p):
    m = re.match(r'https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/(\w+)', p['dist']['url'])
    if not m: raise RuntimeError('no git source for ' + p['name'])
    return {'url': f'https://github.com/{m.group(1)}.git', 'reference': p['dist']['reference']}

def fetch(name):
    p = want[name]; src = p.get('source') or git_source(p); target = 'vendor/' + name
    tmp = tempfile.mkdtemp(prefix='pk.')
    try:
        for cmd in (['git', 'init', '-q'], ['git', 'remote', 'add', 'origin', src['url']], ['git', 'fetch', '-q', '--depth', '1', 'origin', src['reference']]):
            subprocess.run(cmd, cwd=tmp, check=True, timeout=300, capture_output=True)
        stage = target + '.new'; shutil.rmtree(stage, ignore_errors=True); os.makedirs(stage)
        arch = subprocess.Popen(['git', 'archive', 'FETCH_HEAD'], cwd=tmp, stdout=subprocess.PIPE)
        subprocess.run(['tar', '-x', '-C', stage], stdin=arch.stdout, check=True)
        if arch.wait(): raise RuntimeError('git archive failed')
        shutil.rmtree(target, ignore_errors=True); os.replace(stage, target)
        return name, None
    except Exception as e:
        return name, str(e)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

failed = []
if todo:
    print(f'vendor: fetching {len(todo)} package(s) via git')
    with ThreadPoolExecutor(8) as ex:
        for name, err in ex.map(fetch, todo):
            if err: failed.append(name); print('FAIL', name, err)
if failed: sys.exit(1)

pk = []
for n in sorted(want):
    e = dict(want[n]); e['install-path'] = '../' + n; pk.append(e)
json.dump({'packages': pk, 'dev': with_dev, 'dev-package-names': sorted(dev & set(want))}, open('vendor/composer/installed.json', 'w'), indent=4)

def norm(ver):
    v = ver.lstrip('v')
    if not re.match(r'^\d', v): return ver
    parts = v.split('.')
    while len(parts) < 3: parts.append('0')
    return '.'.join(parts) + ('.0' if len(parts) == 3 else '')
rows = ','.join("'%s'=>array('pretty_version'=>'%s','version'=>'%s','reference'=>%s,'type'=>'%s','install_path'=>__DIR__ . \"/../%s\",'aliases'=>array(),'dev_requirement'=>%s)" % (
    p['name'], p['version'], norm(p['version']), ("'%s'" % ref(p)) if ref(p) else 'NULL', p.get('type', 'library'), p['name'], 'true' if p['name'] in dev else 'false') for p in pk)
open('vendor/composer/installed.php', 'w').write("<?php return array('root'=>array('name'=>'__root__','pretty_version'=>'dev-main','version'=>'dev-main','reference'=>NULL,'type'=>'project','install_path'=>__DIR__ . \"/../../\",'aliases'=>array(),'dev'=>%s),'versions'=>array(%s));\n" % ('true' if with_dev else 'false', rows))

os.makedirs('vendor/bin', exist_ok=True)
for n in want:
    try: d = json.load(open(f'vendor/{n}/composer.json'))
    except (OSError, ValueError): continue
    for b in d.get('bin') or []:
        src = f'vendor/{n}/{b}'; dst = 'vendor/bin/' + os.path.basename(b)
        if os.path.exists(src) and not os.path.lexists(dst):
            os.symlink(os.path.relpath(src, 'vendor/bin'), dst); os.chmod(src, 0o755)
print('vendor: ok,', len(pk), 'packages')
