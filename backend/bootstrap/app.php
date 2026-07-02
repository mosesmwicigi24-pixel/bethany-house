<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $middleware->alias([
            'api.key'    => \App\Http\Middleware\ValidateApiKey::class,
            'role'       => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'ensure.2fa' => EnsureTwoFactorIsSetup::class,
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