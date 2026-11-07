<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'status' => 'healthy',
            'service' => 'Nodehi',
            'version' => '1.0.0',
            'environment' => app()->environment(),
            'catalogSize' => Product::query()->active()->count(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function categories(): JsonResponse
    {
        $counts = Product::query()
            ->active()
            ->selectRaw('category_slug, count(*) as total')
            ->groupBy('category_slug')
            ->pluck('total', 'category_slug');

        // تصویر هر دسته از نخستین محصول تصویردارِ همان دسته می‌آید تا کارت
        // دسته بدون کار اضافهٔ مدیر، عکس واقعی بگیرد؛ در نبود عکس، فرانت
        // تصویرسازی SVG خودش را نشان می‌دهد.
        $images = [];
        Product::query()
            ->active()
            ->orderByDesc('stock')
            ->orderBy('id')
            ->get(['category_slug', 'images'])
            ->each(function (Product $p) use (&$images) {
                if (isset($images[$p->category_slug])) {
                    return;
                }

                $first = is_array($p->images) ? ($p->images[0] ?? null) : null;

                if (is_string($first) && $first !== '') {
                    $images[$p->category_slug] = $first;
                }
            });

        $items = Category::query()->active()->orderBy('sort_order')->get()->map(function (Category $c) use ($counts, $images) {
            return [
                'slug' => $c->slug,
                'name' => $c->name,
                'nameEn' => $c->name_en,
                'description' => $c->description,
                'seoIntro' => $c->seo_intro,
                'icon' => $c->icon,
                'featured' => $c->featured,
                'image' => $images[$c->slug] ?? null,
                'count' => (int) ($counts[$c->slug] ?? 0),
            ];
        });

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function brands(): JsonResponse
    {
        $items = Brand::query()->active()->orderBy('sort_order')->get()->map(function (Brand $b) {
            return [
                'slug' => $b->slug,
                'name' => $b->name,
                'nameEn' => $b->name_en,
                'origin' => $b->origin,
                'description' => $b->description,
                'featured' => $b->featured,
            ];
        });

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function category(string $slug): JsonResponse
    {
        $category = Category::query()->active()->where('slug', $slug)->first();

        if (! $category) {
            return response()->json(['ok' => false, 'error' => 'دسته‌بندی یافت نشد.'], 404);
        }

        return response()->json([
            'ok' => true,
            'category' => [
                'slug' => $category->slug,
                'name' => $category->name,
                'nameEn' => $category->name_en,
                'description' => $category->description,
                'seoIntro' => $category->seo_intro,
                'icon' => $category->icon,
            ],
        ]);
    }

    public function brand(string $slug): JsonResponse
    {
        $brand = Brand::query()->active()->where('slug', $slug)->first();

        if (! $brand) {
            return response()->json(['ok' => false, 'error' => 'برند یافت نشد.'], 404);
        }

        return response()->json([
            'ok' => true,
            'brand' => [
                'slug' => $brand->slug,
                'name' => $brand->name,
                'nameEn' => $brand->name_en,
                'origin' => $brand->origin,
                'description' => $brand->description,
            ],
        ]);
    }
}
