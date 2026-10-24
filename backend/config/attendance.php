<?php

/*
 * Bethany People — time clock rules (PR 1, Oct 2026).
 * Explicit and in one place; the geofence radius itself is per outlet
 * (outlets.geofence_radius_meters, Settings › Outlets).
 */
return [
    // A shift still open at this Nairobi wall-clock time on the day it began
    // is closed by attendance:auto-clock-out at exactly this time and flagged.
    'auto_clock_out_time' => env('ATTENDANCE_AUTO_CLOCK_OUT', '22:00'),

    // A punch whose phone time is further than this from the server's receipt
    // time is kept at the phone time and flagged "sent late".
    'late_sync_minutes' => 5,

    // The oldest phone time accepted for a late punch, in hours.
    'late_sync_max_hours' => 24,
];
