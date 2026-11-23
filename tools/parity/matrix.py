"""Builds the controller-level rows of docs/MIGRATION_PARITY_MATRIX.md from xref2.json."""
import json, os, sys, collections, subprocess, re
root, S = sys.argv[1], sys.argv[2]
x = json.load(open(os.path.join(S, 'xref2.json')))
ctl_files = {}
for dp, _, fs in os.walk(os.path.join(root, 'backend/app/Http/Controllers')):
    for f in fs:
        if f.endswith('.php'): ctl_files.setdefault(f[:-4], os.path.relpath(os.path.join(dp, f), root))
def swift_files(uris):
    hits = set()
    for u in uris:
        seg = u.split('/{')[0]
        if len(seg) < 4: continue
        r = subprocess.run(['grep', '-rlF', 'v1/admin/' + seg, os.path.join(root, 'apple/Sources')], capture_output=True, text=True)
        hits |= {os.path.basename(p) for p in r.stdout.split()}
    return sorted(hits)
by = collections.defaultdict(list)
for r in x:
    by[r['action'].split('@')[0] or '(closure)'].append(r)
rows = []
for n, (ctl, rs) in enumerate(sorted(by.items()), 1):
    con = [r for r in rs if r['console']]
    lit = [r for r in con if r['swift']]
    dyn = [r for r in con if r['swift_dyn']]
    gap = [r for r in con if not r['swift'] and not r['swift_dyn']]
    unused = [r for r in rs if not r['console']]
    mods = sorted({r['uri'].split('/')[0] for r in rs})
    if not con: status = 'Under Investigation'
    elif not lit and not dyn: status = 'Not Started'
    elif gap or dyn: status = 'Partially Implemented'
    else: status = 'Implemented but Unverified'
    sw = swift_files([r['uri'] for r in lit + dyn])
    gaps = '; '.join(f"{r['method'].split('|')[0]} {r['uri']}" for r in gap)
    if dyn: gaps = (gaps + '; ' if gaps else '') + 'runtime-built path, action not hand-checked: ' + ', '.join(r['uri'].split('/')[-1] for r in dyn)
    if unused: gaps = (gaps + '; ' if gaps else '') + f'{len(unused)} route(s) the console does not call'
    rows.append((f'HUB-{n:03d}', ', '.join(mods)[:60], ctl, ctl_files.get(ctl, 'UNKNOWN'), f'{len(lit)}+{len(dyn)} of {len(con)} console-called ({len(rs)} routes)',
                 ', '.join(sw[:4]) + (' …' if len(sw) > 4 else '') or '—', status, gaps or '—'))
print('| Feature ID | Module | Function (controller) | Laravel source | Backend contract (Swift literal+runtime of console-called) | Existing Swift code | macOS status | iPadOS status | Verification | Outstanding gaps |')
print('|---|---|---|---|---|---|---|---|---|---|')
for fid, mod, ctl, src, contract, sw, st, gaps in rows:
    ver = 'route xref; both apps compile in CI (apple.yml); not run' if st != 'Not Started' else '—'
    print(f'| {fid} | {mod} | {ctl} | `{src}` | {contract} | {sw} | {st} | {st} | {ver} | {gaps} |')
c = collections.Counter(r[6] for r in rows)
print(); print('<!-- totals: ' + json.dumps(c) + ' -->', file=sys.stderr)
