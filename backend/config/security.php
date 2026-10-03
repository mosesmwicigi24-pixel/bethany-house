<?php

/*
|--------------------------------------------------------------------------
| Sign-in safety (role hardening plan §12.3–12.4, Phase 4C)
|--------------------------------------------------------------------------
|
| Server-side rules for how long a staff session lives, when a privileged
| action needs the person to prove it is still them, when an account locks
| after failed sign-ins, and which roles must use two-step sign-in.
|
| Nothing here is editable from the admin UI: a session policy or 2FA switch a
| signed-in account can change is a way around it. These come from the
| server's environment only.
|
*/

return [

    /*
    | Per-role session limits, in minutes, enforced on every bearer request
    | (App\Services\Auth\SessionPolicy, called from the Sanctum token check in
    | AuthServiceProvider). A person holding several roles gets the strictest
    | of each limit. `idle` is measured from the last request the person made
    | (background polling does not count — see SessionPolicy); `absolute` from
    | sign-in.
    |
    | on_idle: 'sign_out' (the token is revoked, 401) or 'pin_lock' (the token
    | is held until the person's terminal PIN is entered, 423; the register and
    | the sale stay open). A pin_lock role whose holder has no PIN set signs
    | out instead — there is nothing to unlock with.
    */
    'sessions' => [
        // Kill switch for the whole policy. On by default: the plan sets these
        // limits; there is no staged rollout for them.
        'enforce' => (bool) env('SESSION_LIMITS_ENFORCED', true),

        'roles' => [
            'super_admin'         => ['idle' => 15, 'absolute' => 8 * 60],
            'system_admin'        => ['idle' => 15, 'absolute' => 8 * 60],
            'admin'               => ['idle' => 30, 'absolute' => 10 * 60],
            'finance_manager'     => ['idle' => 30, 'absolute' => 10 * 60],
            'procurement_manager' => ['idle' => 30, 'absolute' => 10 * 60],
            'procurement_officer' => ['idle' => 30, 'absolute' => 10 * 60],
            'outlet_manager'      => ['idle' => 30, 'absolute' => 12 * 60],
            'accountant'          => ['idle' => 20, 'absolute' => 10 * 60],
            // Shift ≤ 12 h. "Ends at register close" lands with the till
            // lifecycle (Phase 4B), which owns register close.
            'pos_clerk'           => ['idle' => 5,  'absolute' => 12 * 60, 'on_idle' => 'pin_lock'],
            'tailor'              => ['idle' => 60, 'absolute' => 12 * 60],
        ],

        // A staff or system account whose roles are none of the above (a
        // custom role made on the Roles screen, or no role at all).
        'staff_default' => ['idle' => 30, 'absolute' => 10 * 60],

        // The console marks a request it sends without the person having
        // touched the screen recently (polling) with this header; it does not
        // count as activity for the idle limit.
        'background_header' => 'X-Background-Request',
    ],

    /*
    | Step-up: re-confirming identity (TOTP code when two-step sign-in is on,
    | otherwise the password) before a privileged action. One confirmation
    | covers this many minutes on the session that made it.
    */
    'step_up' => [
        'window_minutes' => 5,
    ],

    /*
    | Account lockout after failed staff sign-ins (wrong password or wrong
    | second-step code), counted per account since its last successful
    | sign-in or unlock. Works alongside the per-IP `throttle:auth` limiter.
    */
    'lockout' => [
        'soft_after'      => 5,     // failures → temporary lock
        'soft_minutes'    => 15,
        'hard_after'      => 10,    // failures within the window → locked until unlocked
        'hard_window_hrs' => 24,
    ],

    /*
    | Two-step sign-in, staged rollout (owner's choice). When a role's switch
    | is on, every holder of that role must have 2FA: an account without it is
    | walked through setup at sign-in before any session is issued. All OFF
    | by default — no staff account had 2FA on when this shipped; the owner
    | turns roles on one at a time.
    */
    'two_factor' => [
        'required_roles' => [
            'super_admin'         => (bool) env('TWO_FACTOR_REQUIRED_SUPER_ADMIN', false),
            'system_admin'        => (bool) env('TWO_FACTOR_REQUIRED_SYSTEM_ADMIN', false),
            'admin'               => (bool) env('TWO_FACTOR_REQUIRED_ADMIN', false),
            'finance_manager'     => (bool) env('TWO_FACTOR_REQUIRED_FINANCE_MANAGER', false),
            'accountant'          => (bool) env('TWO_FACTOR_REQUIRED_ACCOUNTANT', false),
            'procurement_manager' => (bool) env('TWO_FACTOR_REQUIRED_PROCUREMENT_MANAGER', false),
            'outlet_manager'      => (bool) env('TWO_FACTOR_REQUIRED_OUTLET_MANAGER', false),
        ],
        'recovery_codes' => 8,
        // The sign-in setup step (password proved, 2FA not yet on) expires.
        'setup_minutes'  => 15,
    ],

    /*
    | Authority tiers (plan §2). Who may reset whose two-step sign-in, unlock
    | whose account and end whose sessions: system_admin acts on Tier 2–3,
    | super_admin on anyone (another super_admin when the target is one).
    | A role not listed is treated as Tier 1 — only super_admin acts on it.
    */
    'tiers' => [
        'super_admin'         => 0,
        'admin'               => 1,
        'finance_manager'     => 1,
        'system_admin'        => 1,
        'outlet_manager'      => 2,
        'accountant'          => 2,
        'procurement_manager' => 2,
        'pos_clerk'           => 3,
        'tailor'              => 3,
        'procurement_officer' => 3,
    ],

    /*
    | Terminal PIN (clerks): unlocks a PIN-locked session and, from Phase 4B,
    | is the approver's PIN at the till. Wrong attempts are limited per account.
    */
    'terminal_pin' => [
        'max_attempts'  => 5,
        'decay_minutes' => 15,
    ],
];
