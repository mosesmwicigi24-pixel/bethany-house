<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductReviewController extends Controller
{
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
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
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
     * Create review (Customer)
     */
    public function store(Request $request, $productId)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string|max:2000',
            'order_id' => 'nullable|exists:orders,id',
        ]);

        // Check if product exists
        $product = DB::table('products')->find($productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Check if user has purchased the product
        if (isset($validated['order_id'])) {
            $hasPurchased = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
                ->where('orders.id', $validated['order_id'])
                ->where('orders.customer_id', $request->user()->customer->id ?? null)
                ->where('product_variants.product_id', $productId)
                ->exists();

            if (!$hasPurchased) {
                return response()->json([
                    'message' => 'You can only review products you have purchased'
                ], 422);
            }
        }

        // Check if already reviewed
        $existingReview = DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'message' => 'You have already reviewed this product'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $reviewId = DB::table('product_reviews')->insertGetId([
                'product_id' => $productId,
                'user_id' => $request->user()->id,
                'order_id' => $validated['order_id'] ?? null,
                'rating' => $validated['rating'],
                'title' => $validated['title'] ?? null,
                'comment' => $validated['comment'],
                'status' => 'pending', // Requires approval
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update product rating
            $this->updateProductRating($productId);

            DB::commit();

            $review = DB::table('product_reviews')->find($reviewId);

            return response()->json([
                'message' => 'Review submitted successfully. It will be visible after approval.',
                'review' => $review,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to submit review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update review (Customer - own review only)
     */
    public function update(Request $request, $id)
    {
        $review = DB::table('product_reviews')->find($id);

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        // Only allow user to edit their own review
        if ($review->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'sometimes|string|max:2000',
        ]);

        DB::beginTransaction();
        try {
            DB::table('product_reviews')
                ->where('id', $id)
                ->update(array_merge($validated, [
                    'status' => 'pending', // Reset to pending after edit
                    'updated_at' => now(),
                ]));

            // Update product rating
            $this->updateProductRating($review->product_id);

            DB::commit();

            $updated = DB::table('product_reviews')->find($id);

            return response()->json([
                'message' => 'Review updated successfully',
                'review' => $updated,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete review (Customer - own review only)
     */
    public function destroy(Request $request, $id)
    {
        $review = DB::table('product_reviews')->find($id);

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        // Only allow user to delete their own review
        if ($review->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $productId = $review->product_id;

            DB::table('product_reviews')->where('id', $id)->delete();

            // Update product rating
            $this->updateProductRating($productId);

            DB::commit();

            return response()->json([
                'message' => 'Review deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete review',
                'error' => $e->getMessage(),
            ], 500);
        }
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

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
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
            return response()->json([
                'message' => 'Failed to approve review',
                'error' => $e->getMessage(),
            ], 500);
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
            return response()->json([
                'message' => 'Failed to delete review',
                'error' => $e->getMessage(),
            ], 500);
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