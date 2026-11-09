<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SortResolver;
use App\Support\ServerError;

class ProductReviewController extends Controller
{
    /** Columns a client may sort review lists by (columns on product_reviews). */
    private const SORTABLE_COLUMNS = ['created_at', 'updated_at', 'rating', 'helpful_count'];

    /**
     * Get product reviews (Public)
     */
    public function index(Request $request, $productId)
    {
        $query = DB::table('product_reviews')
            ->join('users', 'product_reviews.user_id', '=', 'users.id')
            ->where('product_reviews.product_id', $productId)
            ->where('product_reviews.status', 'approved')
            ->select(
                'product_reviews.*',
                'users.name as customer_name'
            );

        // Filter by rating
        if ($request->has('rating')) {
            $query->where('product_reviews.rating', $request->rating);
        }

        // Sort
        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            self::SORTABLE_COLUMNS,
            'created_at'
        );
        $query->orderBy("product_reviews.{$sortBy}", $sortOrder);

        $perPage = $request->get('per_page', 10);
        $reviews = $query->paginate($perPage);

        // Get rating statistics
        $stats = DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('status', 'approved')
            ->selectRaw('
                COUNT(*) as total_reviews,
                AVG(rating) as average_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
            ')
            ->first();

        return response()->json([
            'reviews' => $reviews,
            'statistics' => $stats,
        ]);
    }

    /**
     * Get single review
     */
    public function show($id)
    {
        $review = DB::table('product_reviews')
            ->join('users', 'product_reviews.user_id', '=', 'users.id')
            ->join('products', 'product_reviews.product_id', '=', 'products.id')
            ->where('product_reviews.id', $id)
            ->select(
                'product_reviews.*',
                'users.name as customer_name',
                'products.name_en as product_name'
            )
            ->first();

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        return response()->json($review);
    }

    /**
     * Get all reviews (Admin)
     */
    public function adminIndex(Request $request)
    {
        $query = DB::table('product_reviews')
            ->join('users', 'product_reviews.user_id', '=', 'users.id')
            ->join('products', 'product_reviews.product_id', '=', 'products.id')
            ->select(
                'product_reviews.*',
                'users.name as customer_name',
                'users.email as customer_email',
                'products.name_en as product_name',
                'products.sku as product_sku'
            );

        // Filter by status
        if ($request->has('status')) {
            $query->where('product_reviews.status', $request->status);
        }

        // Filter by rating
        if ($request->has('rating')) {
            $query->where('product_reviews.rating', $request->rating);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_reviews.product_id', $request->product_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_reviews.comment', 'LIKE', "%{$search}%")
                  ->orWhere('users.name', 'LIKE', "%{$search}%")
                  ->orWhere('products.name_en', 'LIKE', "%{$search}%");
            });
        }

        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            self::SORTABLE_COLUMNS,
            'created_at'
        );
        $query->orderBy("product_reviews.{$sortBy}", $sortOrder);

        $perPage = $request->get('per_page', 20);
        $reviews = $query->paginate($perPage);

        return response()->json($reviews);
    }

    /**
     * Approve review (Admin)
     */
    public function approve($id)
    {
        $review = DB::table('product_reviews')->find($id);

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        DB::beginTransaction();
        try {
            DB::table('product_reviews')
                ->where('id', $id)
                ->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            // Update product rating
            $this->updateProductRating($review->product_id);

            DB::commit();

            try {
                ActivityLogService::log('review_approved', null, [
                    'review_id'  => $id,
                    'product_id' => $review->product_id,
                    'rating'     => $review->rating,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Review approved successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to approve review');
        }
    }

    /**
     * Reject review (Admin)
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $review = DB::table('product_reviews')->find($id);

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        DB::table('product_reviews')
            ->where('id', $id)
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'] ?? null,
                'updated_at' => now(),
            ]);

        try {
            ActivityLogService::log('review_rejected', null, [
                'review_id'  => $id,
                'product_id' => $review->product_id,
                'reason'     => $validated['reason'] ?? null,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Review rejected successfully',
        ]);
    }

    /**
     * Force delete review (Admin)
     */
    public function forceDelete($id)
    {
        $review = DB::table('product_reviews')->find($id);

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        DB::beginTransaction();
        try {
            $productId = $review->product_id;

            DB::table('product_reviews')->where('id', $id)->delete();

            // Update product rating
            $this->updateProductRating($productId);

            DB::commit();

            try {
                ActivityLogService::log('review_force_deleted', null, [
                    'review_id'  => $id,
                    'product_id' => $productId,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Review permanently deleted',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to delete review');
        }
    }

    /**
     * Helper: Update product average rating
     */
    private function updateProductRating($productId)
    {
        $stats = DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('status', 'approved')
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as review_count')
            ->first();

        DB::table('products')
            ->where('id', $productId)
            ->update([
                'average_rating' => $stats->avg_rating ?? 0,
                'review_count' => $stats->review_count ?? 0,
                'updated_at' => now(),
            ]);
    }
}