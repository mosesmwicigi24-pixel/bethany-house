<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product reviews for the storefront (HUB_CONTRACT §8; docs/HUB_STOREFRONT_ENDPOINTS.md).
 *
 *   GET  /storefront/products/{slug}/reviews   rating summary + recent approved reviews
 *   POST /storefront/products/{slug}/reviews   save one customer rating, moderated
 *
 * Called server-side by the storefront only, behind the same optional
 * X-Storefront-Key gate as the leads bridge. A review belongs to the hub
 * product the slug names — the storefront passes a garment's base slug, so
 * its colour and size variants share one rating.
 *
 * Nothing a customer writes is public on its own: a review is saved with
 * is_approved = false and appears only once an administrator approves it in
 * Customers → Reviews & Ratings. The GET reads approved rows only and never
 * returns an email address. "Verified" is not a claim the customer makes: it
 * is set here when a recognised order for the email they gave holds the
 * product (Order::recognised — a sale, never a cart or a cancelled order).
 */
class StorefrontProductReviewController extends Controller
{
    use Concerns\ChecksStorefrontKey;

    /** How many approved reviews the summary carries (most helpful, then newest). */
    private const RECENT = 10;

    public function index(Request $request, string $slug): JsonResponse
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $product = $this->product($slug);
        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $byRating = ProductReview::query()->approved()
            ->where('product_id', $product->id)
            ->selectRaw('rating, COUNT(*) AS n')
            ->groupBy('rating')
            ->pluck('n', 'rating');

        $distribution = [];
        $count = 0;
        $sum = 0;
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $n = (int) ($byRating[$stars] ?? 0);
            $distribution[(string) $stars] = $n;
            $count += $n;
            $sum   += $n * $stars;
        }

        $recent = ProductReview::query()->approved()
            ->where('product_id', $product->id)
            ->with('user')
            ->orderByDesc('helpful_count')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();

        return response()->json([
            'data' => [
                'average'      => $count ? round($sum / $count, 1) : 0,
                'count'        => $count,
                'distribution' => $distribution,
                'reviews'      => $recent->map(fn (ProductReview $r) => $this->item($r))->values(),
            ],
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'client_request_id' => 'nullable|string|max:100',
            'rating'            => 'required|integer|min:1|max:5',
            'title'             => 'nullable|string|max:120',
            'body'              => 'nullable|string|max:2000',
            'author'            => 'nullable|string|max:80',
            'email'             => 'nullable|email|max:160',
        ]);

        $product = $this->product($slug);
        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Idempotency: a retried submission is the same review, never a second row.
        $requestId = $validated['client_request_id'] ?? null;
        if ($requestId && ($existing = ProductReview::where('client_request_id', $requestId)->first())) {
            return response()->json(['review' => $this->item($existing)], 200);
        }

        $email = isset($validated['email']) ? mb_strtolower(trim($validated['email'])) : null;
        $order = $email ? $this->purchaseOf($product, $email) : null;

        try {
            $review = ProductReview::create([
                'product_id'           => $product->id,
                'user_id'              => null,
                'order_id'             => $order?->id,
                'author_name'          => isset($validated['author']) ? trim($validated['author']) : null,
                'author_email'         => $email,
                'client_request_id'    => $requestId,
                'source'               => ProductReview::SOURCE_STOREFRONT,
                'rating'               => (int) $validated['rating'],
                'title'                => $validated['title'] ?? null,
                'review'               => $validated['body'] ?? null,
                'is_verified_purchase' => $order !== null,
                'is_approved'          => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries raced past the lookup above: the first one won.
            $review = ProductReview::where('client_request_id', $requestId)->firstOrFail();
            return response()->json(['review' => $this->item($review)], 200);
        }

        return response()->json(['review' => $this->item($review)], 201);
    }

    /** The published hub product a storefront slug names. */
    private function product(string $slug): ?Product
    {
        return Product::query()->published()->where('slug', $slug)->first();
    }

    /**
     * The newest recognised order for this email that holds the product —
     * matched on the order's own customer_email or its customer record,
     * case-insensitively. Null when the email never bought it.
     */
    private function purchaseOf(Product $product, string $email): ?Order
    {
        return Order::query()->recognised()
            ->where(function ($q) use ($email) {
                $q->whereRaw('LOWER(orders.customer_email) = ?', [$email])
                  ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(email) = ?', [$email]));
            })
            ->whereHas('items', fn ($i) => $i->where('product_id', $product->id))
            ->orderByDesc('orders.id')
            ->first();
    }

    /** One review as the storefront reads it (HUB_CONTRACT §8a). No email, ever. */
    private function item(ProductReview $r): array
    {
        return [
            'id'           => 'rev_' . $r->id,
            'rating'       => (int) $r->rating,
            'title'        => $r->title,
            'body'         => $r->review,
            'author'       => $r->author_label,
            'verified'     => (bool) $r->is_verified_purchase,
            'created_at'   => $r->created_at?->toIso8601String(),
            'helpful_up'   => (int) $r->helpful_count,
            'helpful_down' => 0,
            'status'       => $r->is_approved ? 'published' : 'pending',
        ];
    }
}
