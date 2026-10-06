<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The plain-words answer for a record that is not there (bootstrap/app.php).
 * Soft-deleted → when it was deleted and that it can be restored from the
 * Recycle Bin (only the models the bin supports say so); otherwise → it no
 * longer exists. Always a 404, as before — only the words change.
 */
class MissingRecordMessage
{
    /** Models the Recycle Bin (TrashController) can restore. */
    private const RESTORABLE = [
        \App\Models\Product::class,
        \App\Models\Category::class,
        \App\Models\User::class,
        \App\Models\Customer::class,
    ];

    public static function response(ModelNotFoundException $e): JsonResponse
    {
        $class = $e->getModel();
        $ids   = $e->getIds();
        $label = Str::of(class_basename((string) $class))->snake(' ')->lower()->value() ?: 'record';
        $id    = is_array($ids) ? ($ids[0] ?? null) : $ids;

        $deletedAt = null;
        if ($class && $id !== null && in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $deletedAt = $class::withTrashed()->whereKey($id)->value('deleted_at');
        }

        if ($deletedAt) {
            $when = \Illuminate\Support\Carbon::parse($deletedAt)
                ->timezone(config('app.timezone', 'Africa/Nairobi'))
                ->format('j M Y, H:i');
            $restore = in_array($class, self::RESTORABLE, true)
                ? ' Restore it from Setup → Recycle Bin, or go back to the list.'
                : ' Go back to the list.';

            return response()->json([
                'message'    => "This {$label} was deleted on {$when}.{$restore}",
                'reason'     => 'deleted',
                'deleted_at' => \Illuminate\Support\Carbon::parse($deletedAt)->toIso8601String(),
            ], 404);
        }

        return response()->json([
            'message' => "This {$label} no longer exists — it may have been removed. Go back to the list.",
            'reason'  => 'not_found',
        ], 404);
    }
}
