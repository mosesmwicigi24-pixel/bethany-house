# Bethany House - React Admin

Vite + React 18 + TypeScript SPA that replaces the Livewire admin panel.  
Lives in its own Docker container; consumes the existing Laravel API.

---

## Stack

| Concern | Library |
|---|---|
| Framework | React 18 + Vite 5 + TypeScript |
| Routing | React Router v6 |
| Server state | TanStack Query v5 |
| Global state | Zustand |
| Forms | React Hook Form + Zod |
| Styling | Tailwind CSS (no component library) |
| HTTP | Axios with interceptors |

---

## Directory structure

```
react-admin/
├── src/
│   ├── api/           # API client + per-module API functions
│   ├── components/
│   │   ├── auth/      # RequireAuth, PermissionGate
│   │   ├── layout/    # AdminLayout, Sidebar, Topbar
│   │   └── ui/        # DataTable, Modal, Spinner, Toast, ...
│   ├── hooks/         # usePermissions, useTableState, ...
│   ├── pages/         # Route-level components (one folder per module)
│   ├── store/         # Zustand stores (auth, toast)
│   ├── types/         # Global TypeScript interfaces
│   └── utils/         # Formatters, helpers
├── Dockerfile
├── nginx.conf
└── docker-compose.override.yml
```

---

## Local development

```bash
cd react-admin
cp .env.example .env.local
# Edit VITE_API_URL to point at your Laravel container

npm install
npm run dev
# Opens at http://localhost:5173
```

---

## Docker - add to the stack

1. Place `docker-compose.override.yml` next to your root `docker-compose.yml`  
   Docker Compose merges it automatically.

2. Add the Nginx location block from `nginx-location-block.conf`  
   to `docker/nginx/conf.d/default.conf`.

3. Rebuild:
   ```bash
   docker compose build react-admin
   docker compose up -d react-admin nginx
   ```

4. Access at: `http://your-domain/admin-react`

---

## Laravel API - required additions

Add these routes to `routes/api.php` under an `auth:sanctum` middleware group:

```php
// Admin auth (these complement the existing Livewire auth)
Route::prefix('v1/admin')->group(function () {

    // Public
    Route::post('auth/login',           [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password',  [AuthController::class, 'resetPassword']);
    Route::post('auth/2fa/verify',      [AuthController::class, 'verify2faLogin']);

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me',      [AuthController::class, 'user']);
        Route::get('dashboard',    [DashboardController::class, 'index']);
        // ... module routes added as each module is built
    });
});
```

Add `canAccessAdmin()` check inside `AuthController@login` before issuing the token:

```php
if (!$user->canAccessAdmin()) {
    throw ValidationException::withMessages([
        'email' => ['Only staff and system users may access the admin panel.'],
    ]);
}
```

---

## Adding permissions to the user response

The `usePermissions` hook reads `user.permissions` (array of strings) and  
`user.roles` (array of role objects). Ensure `/api/v1/admin/auth/me` loads these:

```php
public function user(Request $request)
{
    $user = $request->user()->load(['roles.permissions', 'outlet']);
    // Flatten permission names onto the user object for the frontend
    $user->permissions = $user->roles->flatMap->permissions->pluck('name')->unique()->values();
    return response()->json(['user' => $user]);
}
```

---

## Build phases

| Phase | Scope |
|---|---|
| ✅ Foundation | Auth, layout, shared components, Docker |
| Phase 1B | Procurement (suppliers, POs, GRN, returns) |
| Phase 1C | POS (new sale, session, receipts, M-Pesa) |
| Phase 1D | Production (orders, WIP kanban, QC, BOM) |
| Cutover | Swap Nginx `/admin` → react-admin container |

---

## Cutover checklist (when ready)

- [ ] All Phase 1 modules complete and QA'd
- [ ] `/api/v1/admin/*` routes fully covered
- [ ] `user.permissions` populated correctly on `/auth/me`
- [ ] 2FA flow tested end-to-end
- [ ] Change Nginx `/admin` → `proxy_pass http://bethany_react_admin:80`
- [ ] Keep Livewire routes active for 2 weeks as fallback
- [ ] Remove Livewire routes once React admin is stable in production
