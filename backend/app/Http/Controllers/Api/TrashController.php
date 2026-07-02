<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\Customer;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TrashController — Recycle Bin for soft-deleted records.
 *
 * Supported models: products, categories, users, customers
 *
 * Routes (all super_admin only):
 *   GET    /admin/trash                     list all trashed items across models
 *   GET    /admin/trash/{model}             list trashed items for a specific model
 *   POST   /admin/trash/{model}/{id}/restore  restore a single item
 *   DELETE /admin/trash/{model}/{id}          permanently delete a single item
 *   POST   /admin/trash/{model}/restore-all   restore all trashed items for a model
 *   DELETE /admin/trash/{model}/empty         permanently delete all trashed items for a model
 */
class TrashController extends Controller
{
    private const SUPPORTED_MODELS = ['products', 'categories', 'users', 'customers'];

    /**
     * Map URL slugs to model classes.
     */
    private function resolveModel(string $model): string
    {
        return match ($model) {
            'products'   => Product::class,
            'categories' => Category::class,
            'users'      => User::class,
            'customers'  => Customer::class,
            default      => abort(404, "Model '{$model}' not supported in trash."),
        };
    }

    /**
     * GET /admin/trash
     * Returns a summary count of trashed items per model.
     */
    public function summary(): JsonResponse
    {
        $summary = [];
        foreach (self::SUPPORTED_MODELS as $model) {
            $class = $this->resolveModel($model);
            $summary[$model] = $class::onlyTrashed()->count();
        }

        return response()->json([
            'summary' => $summary,
            'total'   => array_sum($summary),
        ]);
    }

    /**
     * GET /admin/trash/{model}
     * List trashed items for a specific model with pagination and search.
     */
    public function index(Request $request, string $model): JsonResponse
    {
        $validated = $request->validate([
            'search'   => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $class    = $this->resolveModel($model);
        $search   = $validated['search'] ?? null;
        $perPage  = $validated['per_page'] ?? 20;

        $query = $class::onlyTrashed()->orderByDesc('deleted_at');

        // Model-specific search and eager loading
        switch ($model) {
            case 'products':
                $query->with(['translations' => fn ($q) => $q->where('language_code', 'en')]);
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('sku', 'ILIKE', "%{$search}%")
                          ->orWhereHas('translations', fn ($t) =>
                              $t->where('language_code', 'en')
                                ->where('name', 'ILIKE', "%{$search}%")
                          );
                    });
                }
                break;

            case 'categories':
                if ($search) {
                    $query->where('name_en', 'ILIKE', "%{$search}%");
                }
                break;

            case 'users':
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('first_name', 'ILIKE', "%{$search}%")
                          ->orWhere('last_name',  'ILIKE', "%{$search}%")
                          ->orWhere('email',       'ILIKE', "%{$search}%");
                    });
                }
                break;

            case 'customers':
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('first_name',       'ILIKE', "%{$search}%")
                          ->orWhere('last_name',        'ILIKE', "%{$search}%")
                          ->orWhere('email',            'ILIKE', "%{$search}%")
                          ->orWhere('customer_number', 'ILIKE', "%{$search}%");
                    });
                }
                break;
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total'        => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    /**
     * POST /admin/trash/{model}/{id}/restore
     * Restore a single soft-deleted item.
     */
    public function restore(string $model, int $id): JsonResponse
    {
        $class = $this->resolveModel($model);
        $item  = $class::onlyTrashed()->findOrFail($id);

        $item->restore();

        try {
            ActivityLogService::log('trash_restored', $item, [
                'model' => $model,
                'id'    => $id,
            ], "Restored {$model} #{$id} from trash");
        } catch (\Exception) {}

        return response()->json([
            'message' => ucfirst(rtrim($model, 's')) . " restored successfully.",
            'item'    => $item->fresh(),
        ]);
    }

    /**
     * DELETE /admin/trash/{model}/{id}
     * Permanently delete a single soft-deleted item (force delete).
     */
    public function forceDelete(string $model, int $id): JsonResponse
    {
        $class = $this->resolveModel($model);
        $item  = $class::onlyTrashed()->findOrFail($id);

        // For products, clean up related records before force-deleting
        if ($model === 'products') {
            DB::transaction(function () use ($item) {
                $item->translations()->delete();
                $item->prices()->delete();
                $item->images()->delete();
                $item->seo()->delete();
                $item->forceDelete();
            });
        } else {
            $item->forceDelete();
        }

        try {
            ActivityLogService::log('trash_force_deleted', null, [
                'model' => $model,
                'id'    => $id,
            ], "Permanently deleted {$model} #{$id} from trash");
        } catch (\Exception) {}

        return response()->json([
            'message' => ucfirst(rtrim($model, 's')) . " permanently deleted.",
        ]);
    }

    /**
     * POST /admin/trash/{model}/restore-all
     * Restore all soft-deleted items for a model.
     */
    public function restoreAll(string $model): JsonResponse
    {
        $class = $this->resolveModel($model);
        $count = $class::onlyTrashed()->count();
        $class::onlyTrashed()->restore();

        try {
            ActivityLogService::log('trash_restore_all', null, [
                'model' => $model,
                'count' => $count,
            ], "Restored all {$count} {$model} from trash");
        } catch (\Exception) {}

        return response()->json([
            'message' => "{$count} " . rtrim($model, 's') . "(s) restored.",
            'count'   => $count,
        ]);
    }

    /**
     * DELETE /admin/trash/{model}/empty
     * Permanently delete ALL trashed items for a model (empty the bin for that model).
     */
    public function emptyModel(string $model): JsonResponse
    {
        $class = $this->resolveModel($model);
        $items = $class::onlyTrashed()->get();
        $count = $items->count();

        if ($model === 'products') {
            DB::transaction(function () use ($items) {
                foreach ($items as $product) {
                    $product->translations()->delete();
                    $product->prices()->delete();
                    $product->images()->delete();
                    $product->seo()->delete();
                    $product->forceDelete();
                }
            });
        } else {
            $class::onlyTrashed()->forceDelete();
        }

        try {
            ActivityLogService::log('trash_emptied', null, [
                'model' => $model,
                'count' => $count,
            ], "Emptied {$model} trash — {$count} record(s) permanently deleted");
        } catch (\Exception) {}

        return response()->json([
            'message' => "Trash emptied — {$count} " . rtrim($model, 's') . "(s) permanently deleted.",
            'count'   => $count,
        ]);
    }
}