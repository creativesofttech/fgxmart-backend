<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, ProductSearch $search)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']]);
        $matches = $search->matches($data['q']);
        $query = Product::where('status', true)->with(['brand:id,name', 'category:id,name', 'media']);
        $search->apply($query, array_slice($matches, 0, 6));
        $products = $query->get()->map(fn ($product) => [
            'id' => $product->id, 'name' => $product->name, 'slug' => $product->slug,
            'base_price' => $product->base_price, 'discount_price' => $product->discount_price,
            'cover_image_url' => $product->cover_image_url,
            'media' => $product->media->take(1)->map(fn ($media) => ['file_url' => $media->file_url])->values(),
            'brand' => $product->brand?->only(['id', 'name']),
            'category' => $product->category?->only(['id', 'name']),
        ]);
        return response()->json(['data' => $products, 'meta' => ['query' => $data['q'], 'total' => count($matches), 'has_typo_matches' => collect(array_slice($matches, 0, 6))->contains('typo', true)]])
            ->header('Cache-Control', 'no-store');
    }
}
