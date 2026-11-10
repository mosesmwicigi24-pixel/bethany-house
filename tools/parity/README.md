# Parity cross-reference

Regenerates the evidence behind `docs/MIGRATION_PARITY_MATRIX.md`: which
`/api/v1/admin` routes the console, the Apple apps and the Android app call.
Read-only; it needs a backend checkout with `vendor/` and no database.

```sh
OUT=$(mktemp -d)
(cd backend && php artisan route:list --json) > "$OUT/routes.json"
python3 tools/parity/xref.py . "$OUT"           # literal paths       -> xref.json
python3 tools/parity/xref_runtime.py . "$OUT"   # runtime-built paths -> xref2.json, gap list on stdout
python3 tools/parity/matrix.py . "$OUT"         # controller rows of the matrix (markdown)
```

Limits, stated so nobody reads more into the numbers than they hold:

- Matching is by path pattern, not HTTP verb.
- A "runtime-built" hit (`/\(action)` in Swift, `${x}` in TypeScript) means a
  client builds a path that *could* be the route; whether it ever sends that
  action is checked by hand. About eight such hits were false on 2026-11-10
  (listed in the matrix).
- Calling an endpoint is not parity. A screen can call every route and still
  differ in what it shows; that is checked per slice, against the console page.
