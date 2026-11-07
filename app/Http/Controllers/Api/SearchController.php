<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'query' => $q, 'products' => [], 'categories' => [], 'brands' => []]);
        }

        $products = Product::query()
            ->active()
            ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('short_description', 'like', "%{$q}%"))
            ->orderByDesc('review_count')
            ->limit(8)
            ->get(['slug', 'name', 'price', 'compare_at_price', 'images'])
            ->map(fn (Product $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'price' => $p->price,
                'compareAtPrice' => $p->compare_at_price,
                'image' => $p->images[0] ?? null,
            ]);

        $categories = Category::query()->active()
            ->where('name', 'like', "%{$q}%")
            ->limit(4)
            ->get(['slug', 'name']);

        $brands = Brand::query()->active()
            ->where('name', 'like', "%{$q}%")
            ->limit(4)
            ->get(['slug', 'name']);

        return response()->json([
            'ok' => true,
            'query' => $q,
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
        ]);
    }
}
