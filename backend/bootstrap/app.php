<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTwoFactorIsSetup;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the reverse proxy, so $request->ip() is the REAL client.
        //
        // Without this, Laravel reports the immediate peer — the Docker bridge
        // gateway nginx connects from — for every single request on the box.
        // Twelve places depend on that value, and all of them were wrong:
        //
        //   • SEVEN rate limiters key ->by($request->ip()), including
        //     'auth' at Limit::perMinute(5). One shared bucket meant ANY five
        //     failed logins anywhere in the company locked out EVERYONE for
        //     the rest of that minute. Five attempts is a sane per-person
        //     limit and a crippling per-company one.
        //   • AuditLog::ip_address recorded the gateway, so the audit trail
        //     could not attribute an action to where it came from.
        //   • ValidateApiKey and RestrictToIPs compare $request->ip() against
        //     an allowlist. Both were matching on the gateway address, so an
        //     IP allowlist either admitted every client or none — it could
        //     never do the job it exists for.
        //   • RoleMiddleware and LogApiRequests logged the gateway on every
        //     security event.
        //
        // Trusting the proxy is safe here specifically because the container
        // publishes to 127.0.0.1:8010 — loopback only. Nothing off the box can
        // reach Laravel directly, so X-Forwarded-For cannot be spoofed from
        // outside. Private ranges are listed explicitly rather than using '*',
        // so this stays correct if the app is ever exposed more widely.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'api.key'    => \App\Http\Middleware\ValidateApiKey::class,
            'role'       => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'ensure.2fa' => EnsureTwoFactorIsSetup::class,
            // The staff boundary on the whole /admin/* API. canAccessAdmin()
            // (isSystem || isStaff) was enforced only at admin login; without
            // this, any authenticated user — a self-registered customer
            // included — reached every admin route lacking a per-route
            // permission gate.
            'ensure.staff' => EnsureStaff::class,
        ]);

        // Configure authentication redirects
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('admin/*') || $request->is('admin')) {
                return route('admin.login');
            }
            return route('admin.login');
        });
    })
    ->withCommands([
        \App\Console\Commands\RunIntelligenceChecks::class,
        \App\Console\Commands\SendEodReports::class,
        \App\Console\Commands\SendOverdueProductionNotifications::class,
        \App\Console\Commands\PurgeOldActivityLogs::class,
        \App\Console\Commands\RunScheduledBackups::class,
    ])
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // EoD report delivery — runs every minute, command handles time-of-day
        // and already-sent checks internally so no duplicate sends occur.
        $schedule->command('eod:send-reports')->everyMinute();

        $schedule->command('notifications:overdue-production')->dailyAt('08:00');
        $schedule->command('logs:purge-old')->weekly();

        // Scheduled database backups — runs every minute, the command itself
        // checks the backup_schedules config row and only actually performs a
        // backup once per configured slot (time of day + frequency), so this
        // is cheap on every tick that isn't due and lets the schedule be
        // reconfigured from the admin UI without redeploying.
        $schedule->command('database:run-scheduled-backups')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();