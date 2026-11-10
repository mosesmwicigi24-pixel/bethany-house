"""Second pass over xref.json: a route the literal match missed counts as
'dynamic' for a client when one of that client's path literals, with every
interpolation (\\(x), ${x}, $x) read as one segment, matches the route.
Dynamic hits mean the client builds the path at runtime (e.g. /\\(action));
whether that action is ever 'approve' is checked by hand, not here."""
import json, os, re, sys, collections
root, S = sys.argv[1], sys.argv[2]
x = json.load(open(os.path.join(S, 'xref.json')))
def lits(dirs, exts, interp):
    pats = set()
    for d in dirs:
        for dp, _, fs in os.walk(os.path.join(root, d)):
            if '/node_modules' in dp or '/build/' in dp or '/test' in dp.lower(): continue
            for f in fs:
                if not f.endswith(exts): continue
                t = open(os.path.join(dp, f), errors='ignore').read()
                # Blank interpolations first, so quotes inside one (\(run["id"]?.int ?? 0))
                # do not end the string literal early.
                t = re.sub(interp, '\x01', t)
                for m in re.finditer(r'["`]/?v1/admin/([^"`\n]+)["`]', t):
                    s = m.group(1).split('?')[0]
                    rx = re.escape(s).replace('\x01', '[^/]+')
                    pats.add(rx)
    return [re.compile('^' + p + '$') for p in pats]
P = {
 'swift': lits(['apple/Sources','apple/Apps'], ('.swift',), r'\\\((?:[^()]|\([^()]*\))*\)'),
 'console': lits(['react-admin/src'], ('.ts','.tsx'), r'\$\{[^}]*\}'),
 'android': lits(['android/app/src/main','android/core/src/main'], ('.kt',), r'\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_.]*'),
}
for r in x:
    probe = '/'.join('X1' if p.startswith('{') else p for p in r['uri'].split('/'))
    for k in P:
        r[k+'_dyn'] = (not r[k]) and any(p.match(probe) for p in P[k])
json.dump(x, open(os.path.join(S, 'xref2.json'), 'w'), indent=0)
gap = [r for r in x if r['console'] and not r['swift']]
print('console-called, no literal swift:', len(gap), '| of which swift dynamic:', sum(r['swift_dyn'] for r in gap))
for r in gap:
    print(('DYN ' if r['swift_dyn'] else 'GAP ') + r['method'].split('|')[0] + ' ' + r['uri'] + '  [' + r['action'] + ']')
