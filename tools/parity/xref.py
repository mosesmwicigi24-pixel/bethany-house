"""Cross-reference every /api/v1/admin route with the paths each client's source calls.

A route counts as called by a client when its path pattern (static segments
literal, {params} as any segment) occurs in that client's source, either as
a full path ("/v1/admin/orders/${id}/refund") or after a module base constant
("$BASE/products" where BASE ends in "/pos"). Heuristic: verb not checked."""
import json, os, re, sys, collections
root, S = sys.argv[1], sys.argv[2]
routes = json.load(open(os.path.join(S, 'routes.json')))
def load(dirs, exts):
    buf = []
    for d in dirs:
        for dp, _, fs in os.walk(os.path.join(root, d)):
            if '/node_modules' in dp or '/build/' in dp or '/test' in dp.lower(): continue
            for f in fs:
                if f.endswith(exts): buf.append(open(os.path.join(dp, f), errors='ignore').read())
    return '\n'.join(buf)
src = {
    'console': load(['react-admin/src'], ('.ts', '.tsx')),
    'swift': load(['apple/Sources', 'apple/Apps'], ('.swift',)),
    'android': load(['android/app/src/main', 'android/core/src/main'], ('.kt',)),
}
# base constants per client: e.g. BASE = "/v1/admin/pos" or base = "/v1/admin/kiosk"
bases = {k: set(m.group(1) for m in re.finditer(r'(?:BASE|base)\s*(?::\s*String)?\s*=\s*"/v1/admin/([a-z0-9/_-]+)"', v)) for k, v in src.items()}
SEG = r'[^/"\'`\s?]+'
out = []
for r in routes:
    uri = r['uri']
    if not uri.startswith('api/v1/admin/'): continue
    tail = uri[len('api/v1/admin/'):]
    parts = tail.split('/')
    rx = '/'.join(SEG if p.startswith('{') else re.escape(p) for p in parts)
    row = {'uri': tail, 'method': r['method'], 'action': (r.get('action') or '').split('\\')[-1]}
    for k, text in src.items():
        hit = re.search(r'(?:v1/admin/|["\'`]/?)' + rx + r'(?=["\'`?/)]|$)', text) is not None
        if not hit:
            for b in bases[k]:
                if tail.startswith(b + '/') or tail == b:
                    rest = tail[len(b):].lstrip('/')
                    rx2 = '/'.join(SEG if p.startswith('{') else re.escape(p) for p in rest.split('/')) if rest else ''
                    if rx2 and re.search(r'(?:BASE\}?|base\)?)/' + rx2 + r'(?=["\'`?/)]|$)', text): hit = True
        row[k] = hit
    out.append(row)
json.dump(out, open(os.path.join(S, 'xref.json'), 'w'), indent=0)
c = collections.Counter((x['console'], x['swift'], x['android']) for x in out)
print('admin routes', len(out))
for (co, sw, an), n in sorted(c.items(), key=lambda kv: -kv[1]):
    print(f"console={co!s:5} swift={sw!s:5} android={an!s:5} : {n}")
print('console-called', sum(x['console'] for x in out), '| swift', sum(x['swift'] for x in out), '| android', sum(x['android'] for x in out))
print('console-called but not swift:', sum(x['console'] and not x['swift'] for x in out))
print('swift bases', sorted(bases['swift'])[:20])
