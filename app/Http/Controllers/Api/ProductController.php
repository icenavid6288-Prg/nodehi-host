<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('perPage', 24), 1), 60);

        $query = Product::query()->active();
        $this->applyFilters($query, $request);
        $this->applySort($query, (string) $request->query('sort', 'relevance'), (string) $request->query('q', ''));

        $total = (clone $query)->count();
        $page = max((int) $request->query('page', 1), 1);
        $items = $query->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'sort' => (string) $request->query('sort', 'relevance'),
            'query' => (string) $request->query('q', ''),
            'items' => $items->map(fn (Product $p) => $this->serialize($p))->all(),
        ]);
    }

    /**
     * جزئیات چند محصول در یک درخواست — مخصوص جدول مقایسه و نوار شناور آن.
     *
     * ترتیب نامک‌های درخواستی حفظ می‌شود تا ستون‌های جدول مقایسه با ترتیب
     * انتخاب کاربر یکی باشد؛ محصول غیرفعال یا حذف‌شده بی‌صدا نادیده گرفته می‌شود.
     */
    public function bySlugs(Request $request): JsonResponse
    {
        $slugs = array_slice(
            array_values(array_filter(array_map('trim', explode(',', (string) $request->query('slugs', ''))))),
            0,
            8
        );

        if ($slugs === []) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $products = Product::query()->active()->whereIn('slug', $slugs)->get()->keyBy('slug');

        $items = collect($slugs)
            ->map(fn (string $slug) => $products->get($slug))
            ->filter()
            ->map(fn (Product $p) => $this->serialize($p) + [
                'description' => $p->description,
                'specs' => $p->specs,
                'attributes' => $p->attributes,
                'videoUrl' => $p->video_url,
            ])
            ->values();

        return response()->json(['ok' => true, 'items' => $items->all()]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()->active()->where('slug', $slug)->first();

        if (! $product) {
            return response()->json(['ok' => false, 'error' => 'محصول یافت نشد.'], 404);
        }

        $related = Product::query()
            ->active()
            ->whereIn('slug', $product->related_slugs ?: [])
            ->get();

        return response()->json([
            'ok' => true,
            'product' => $this->serialize($product) + [
                'description' => $product->description,
                'specs' => $product->specs,
                'attributes' => $product->attributes,
                'videoUrl' => $product->video_url,
            ],
            'related' => $related->map(fn (Product $p) => $this->serialize($p))->all(),
        ]);
    }

    public function reviews(string $slug): JsonResponse
    {
        $reviews = Review::query()
            ->where('product_slug', $slug)
            ->where('status', 'approved')
            ->latest()
            ->limit(50)
            ->get(['id', 'author', 'rating', 'title', 'body', 'created_at']);

        return response()->json(['ok' => true, 'items' => $reviews]);
    }

    public function storeReview(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'author' => ['nullable', 'string', 'max:80'],
        ]);

        if (! Product::query()->active()->where('slug', $slug)->exists()) {
            return response()->json(['ok' => false, 'error' => 'محصول یافت نشد.'], 404);
        }

        $user = $request->user();
        $values = [
            'author' => $data['author'] ?? $user?->fullName() ?? 'کاربر نودهی',
            'rating' => $data['rating'],
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => 'pending',
        ];

        // کاربر واردشده فقط نظر خودش را به‌روز می‌کند؛ نظر مهمان نباید
        // با نظر مهمان دیگری (که user_id تهی دارد) جایگزین شود.
        $review = $user
            ? Review::updateOrCreate(['product_slug' => $slug, 'user_id' => $user->id], $values)
            : Review::create($values + ['product_slug' => $slug, 'user_id' => null]);

        return response()->json([
            'ok' => true,
            'message' => 'نظر شما ثبت شد و پس از تأیید نمایش داده می‌شود.',
            'review' => $review,
        ], 201);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('short_description', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        foreach (['category' => 'category_slug', 'brand' => 'brand_slug'] as $param => $column) {
            if ($value = $request->query($param)) {
                $values = is_array($value) ? $value : explode(',', (string) $value);
                $query->whereIn($column, array_filter($values));
            }
        }

        if ($min = $request->query('minPrice')) {
            $query->where('price', '>=', (int) $min);
        }
        if ($max = $request->query('maxPrice')) {
            $query->where('price', '<=', (int) $max);
        }
        if ($request->boolean('inStock')) {
            $query->where('stock', '>', 0);
        }
        if ($request->boolean('onSale')) {
            $query->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price');
        }
        if ($tag = $request->query('tag')) {
            $query->whereJsonContains('tags', $tag);
        }
    }

    private function applySort(Builder $query, string $sort, string $q): void
    {
        match ($sort) {
            'price-asc' => $query->orderBy('price'),
            'price-desc' => $query->orderByDesc('price'),
            'newest' => $query->orderByDesc('published_at'),
            'popular' => $query->orderByDesc('review_count'),
            'rating' => $query->orderByDesc('rating'),
            'discount' => $query->orderByRaw('(compare_at_price - price) DESC'),
            default => $q === ''
                ? $query->orderByDesc('review_count')->orderByDesc('rating')
                : $query->orderByDesc('rating'),
        };
    }

    public function serialize(Product $p): array
    {
        return [
            'id' => $p->slug,
            'slug' => $p->slug,
            'name' => $p->name,
            'category' => $p->category_slug,
            'brand' => $p->brand_slug,
            'price' => $p->price,
            'compareAtPrice' => $p->compare_at_price,
            'discountPercent' => $p->discountPercent(),
            'stock' => $p->stock,
            'inStock' => $p->stock > 0,
            'rating' => $p->rating,
            'reviewCount' => $p->review_count,
            'shortDescription' => $p->short_description,
            'highlights' => $p->highlights,
            'images' => $p->images,
            'tags' => $p->tags,
            'relatedSlugs' => $p->related_slugs,
            'readyToShip' => (bool) (($p->attributes['readyToShip'] ?? false)),
            'publishedAt' => optional($p->published_at)->toIso8601String(),
        ];
    }
}
