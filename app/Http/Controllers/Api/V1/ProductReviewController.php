<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductReviewController extends Controller
{
    public function index(Request $request, Product $product)
    {
        abort_unless($product->status, 404);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $query = $product->reviews()->where('is_approved', true)->whereHas('user');
        $counts = (clone $query)->selectRaw('ROUND(rating) as stars, COUNT(*) as total')->groupByRaw('ROUND(rating)')->pluck('total', 'stars');
        $total = (int) $counts->sum();
        $reviews = (clone $query)->with('user:id,name')->latest()->orderByDesc('id')->paginate(6);
        return response()->json([
            'data' => $reviews->getCollection()->map(fn ($review) => [
                'id' => $review->id, 'name' => $review->user->name,
                'rating' => (float) $review->rating, 'review' => $review->review,
                'created_at' => $review->created_at->toIso8601String(),
            ]),
            'summary' => [
                'count' => $total, 'average' => $total ? round((float) (clone $query)->avg('rating'), 1) : null,
                'distribution' => collect(range(5, 1))->map(fn ($star) => ['rating' => $star, 'count' => (int) ($counts[$star] ?? 0)])->values(),
            ],
            'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage(), 'total' => $reviews->total()],
        ]);
    }

    public function mine(Request $request, Product $product)
    {
        abort_unless($product->status, 404);
        $review = $product->reviews()->where('user_id', $request->user()->id)->first();
        return response()->json(['data' => $review?->only(['rating', 'review', 'is_approved'])]);
    }

    public function store(Request $request, Product $product)
    {
        abort_unless($product->status, 404);
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'review' => 'required|string|min:10|max:2000']);
        $review = DB::transaction(function () use ($request, $product, $data) {
            // Serialize submissions from this customer, including simultaneous first reviews.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            return ProductReview::updateOrCreate(
                ['user_id' => $request->user()->id, 'product_id' => $product->id],
                ['rating' => $data['rating'], 'review' => $data['review']],
            );
        });
        $review->refresh();
        return response()->json(['message' => $review->is_approved ? 'Thank you. Your review has been saved.' : 'Your review has been saved and is awaiting approval.', 'data' => $review->only(['rating', 'review', 'is_approved'])]);
    }
}
