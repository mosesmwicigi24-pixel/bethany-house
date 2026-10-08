#!/usr/bin/env node
/**
 * Navigation follows permission (Role Hardening Plan §16).
 *
 * Fails (exit 1) when, for any page:
 *   - a Sidebar item's gate differs from its App.tsx route guard,
 *   - a command-palette shortcut's gate differs from that route guard,
 *   - a Sidebar item or shortcut points at a path App.tsx does not guard
 *     the same way (or does not define), or
 *   - App.tsx guards a route by role name (role= / anyOfRoles=) instead of
 *     the permission its API checks (superAdminOnly is the one exception,
 *     for pages whose API is itself role:super_admin).
 *
 * Usage:  npm run check:nav     (or: node scripts/check-nav-gates.mjs)
 *
 * No dependencies: it reads the three source files as text. It understands
 * the shapes those files use today — object literals with `href: "..."` and
 * `<Route path="..." element={<ProtectedRoute ...>}>` — and says so loudly if
 * it finds nothing, rather than passing vacuously.
 */
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..", "src");
const read = (p) => readFileSync(join(root, p), "utf8");

const stripComments = (src) =>
    src
        .replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, "") // JSX {/* … */}
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/(^|[^:"'])\/\/[^\n]*/g, "$1");

const list = (s) => (s ? [...s.matchAll(/"([^"]+)"/g)].map((m) => m[1]) : []);

/** Gate → one comparable string. permission:x ≡ anyOf:[x]. */
function norm(g) {
    if (g.superAdminOnly) return "superAdminOnly";
    let permission = g.permission;
    let anyOf = g.anyOf ?? [];
    if (!permission && anyOf.length === 1) {
        permission = anyOf[0];
        anyOf = [];
    }
    const parts = [];
    if (permission) parts.push(`permission:${permission}`);
    if (anyOf.length) parts.push(`anyOf:${[...anyOf].sort().join("|")}`);
    if (g.allOf?.length) parts.push(`allOf:${[...g.allOf].sort().join("|")}`);
    return parts.join(" & ") || "(no gate)";
}

/** Innermost object literals that carry an href, from a slice of source. */
function objectGates(src) {
    const out = new Map();
    for (const m of src.matchAll(/\{[^{}]*?href:\s*"([^"]+)"[^{}]*?\}/g)) {
        const body = m[0];
        out.set(m[1], {
            permission: body.match(/\bpermission:\s*"([^"]+)"/)?.[1],
            anyOf: list(body.match(/\banyOfPermissions:\s*\[([^\]]*)\]/)?.[1]),
            allOf: list(body.match(/\ballOfPermissions:\s*\[([^\]]*)\]/)?.[1]),
            superAdminOnly: /\bsuperAdminOnly:\s*true/.test(body),
        });
    }
    return out;
}

function between(src, startMarker, label) {
    const i = src.indexOf(startMarker);
    if (i < 0) throw new Error(`${label}: cannot find "${startMarker}"`);
    const j = src.indexOf("\n];", i);
    return src.slice(i, j);
}

// ── Sources ──────────────────────────────────────────────────────────────────
const sidebar = objectGates(between(stripComments(read("components/layout/Sidebar.tsx")), "const NAV: NavGroup[] = [", "Sidebar"));
const palette = objectGates(between(stripComments(read("components/ui/CommandPalette.tsx")), "const NAV_SHORTCUTS: NavShortcut[] = [", "CommandPalette"));

const appSrc = stripComments(read("App.tsx"));
const routes = new Map();
const problems = [];
const pathRe = /<Route\s+path="([^"]+)"/g;
const hits = [...appSrc.matchAll(pathRe)];
hits.forEach((m, idx) => {
    const end = idx + 1 < hits.length ? hits[idx + 1].index : appSrc.length;
    const chunk = appSrc.slice(m.index, end);
    const tag = chunk.match(/<ProtectedRoute\b([^>]*)>/)?.[1];
    if (tag === undefined) {
        routes.set(m[1], {});
        return;
    }
    if (/\b(role|anyOfRoles)=/.test(tag)) {
        problems.push(`App.tsx ${m[1]}: guarded by role name (${tag.trim()}) — use the permission its API checks, or superAdminOnly`);
    }
    routes.set(m[1], {
        permission: tag.match(/\bpermission="([^"]+)"/)?.[1],
        anyOf: list(tag.match(/\banyOf=\{\[([^\]]*)\]\}/)?.[1]),
        allOf: list(tag.match(/\ballOf=\{\[([^\]]*)\]\}/)?.[1]),
        superAdminOnly: /\bsuperAdminOnly\b/.test(tag),
    });
});

if (sidebar.size < 20 || routes.size < 20 || palette.size < 5) {
    console.error(`check-nav-gates: parsed too little (sidebar ${sidebar.size}, routes ${routes.size}, palette ${palette.size}) — the source shape changed; update this script.`);
    process.exit(1);
}

// ── Compare ──────────────────────────────────────────────────────────────────
const compare = (source, entries) => {
    for (const [href, gate] of entries) {
        if (!routes.has(href)) {
            problems.push(`${source} ${href}: no <Route path="${href}"> in App.tsx`);
            continue;
        }
        const want = norm(gate);
        const got = norm(routes.get(href));
        if (want !== got) problems.push(`${source} ${href}: menu gate [${want}] ≠ route guard [${got}]`);
    }
};
compare("Sidebar", sidebar);
compare("CommandPalette", palette);

if (problems.length) {
    console.error(`Navigation gates disagree (${problems.length}):\n  - ${problems.join("\n  - ")}`);
    process.exit(1);
}
console.log(`Navigation gates agree: ${sidebar.size} sidebar items, ${palette.size} palette shortcuts, ${routes.size} routes checked.`);
