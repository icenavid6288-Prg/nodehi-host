<?php

namespace App\Http\Controllers\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * مدیریت کاتالوگ در پنل مدیریت.
 *
 * برخلاف API عمومی، محصولات غیرفعال هم دیده می‌شوند. «نامک» پس از ایجاد
 * قابل تغییر نیست چون سفارش‌های قدیمی و نظرها با همین نامک ارجاع داده
 * شده‌اند؛ تغییر آن یعنی شکستن تاریخچهٔ فروش.
 */
class AdminProductController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('short_description', 'like', "%{$q}%");
            });
        }

        if ($category = trim((string) $request->query('category', ''))) {
            $query->where('category_slug', $category);
        }

        if ($brand = trim((string) $request->query('brand', ''))) {
            $query->where('brand_slug', $brand);
        }

        match ((string) $request->query('status', '')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'out_of_stock' => $query->where('stock', '<=', 0),
            'low_stock' => $query->where('stock', '>', 0)->where('stock', '<=', 5),
            default => null,
        };

        $total = (clone $query)->count();
        $perPage = $this->perPage($request);
        $page = $this->page($request);

        $products = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $categoryNames = Category::query()
            ->whereIn('slug', $products->pluck('category_slug')->unique())
            ->pluck('name', 'slug');
        $brandNames = Brand::query()
            ->whereIn('slug', $products->pluck('brand_slug')->unique())
            ->pluck('name', 'slug');

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'items' => $products->map(fn (Product $p) => $this->serialize($p, $categoryNames->all(), $brandNames->all()))->all(),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['ok' => true, 'product' => $this->serialize($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());

        $product = Product::create($this->columns($data) + [
            'published_at' => isset($data['publishedAt']) ? $data['publishedAt'] : now(),
        ]);

        $this->audit($request, 'product.created', 'product', $product->id, ['slug' => $product->slug]);

        return response()->json(['ok' => true, 'product' => $this->serialize($product->fresh())], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->rules($product), $this->messages());

        $product->fill($this->columns($data));
        $product->save();

        $this->audit($request, 'product.updated', 'product', $product->id, [
            'slug' => $product->slug,
            'fields' => array_keys($data),
        ]);

        return response()->json(['ok' => true, 'product' => $this->serialize($product->fresh())]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        if (OrderItem::query()->where('product_slug', $product->slug)->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'این محصول در سفارش‌های ثبت‌شده استفاده شده است؛ برای حذف اثر آن، محصول را غیرفعال کنید.',
            ], 422);
        }

        DB::transaction(function () use ($product) {
            Review::query()->where('product_slug', $product->slug)->delete();
            $product->delete();
        });

        $this->audit($request, 'product.deleted', 'product', $product->id, ['slug' => $product->slug]);

        return response()->json(['ok' => true, 'message' => 'محصول حذف شد.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Product $product = null): array
    {
        $creating = $product === null;

        return [
            'slug' => $product
                ? ['prohibited']
                : ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'categorySlug' => [$creating ? 'required' : 'sometimes', 'string', Rule::exists('categories', 'slug')],
            'brandSlug' => [$creating ? 'required' : 'sometimes', 'string', Rule::exists('brands', 'slug')],
            'price' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:2000000000'],
            'compareAtPrice' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000000000'],
            'stock' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:1000000'],
            'shortDescription' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'highlights' => ['sometimes', 'array', 'max:12'],
            'highlights.*' => ['string', 'max:200'],
            'specs' => ['sometimes', 'array', 'max:40'],
            'specs.*.label' => ['required', 'string', 'max:80'],
            'specs.*.value' => ['required', 'string', 'max:200'],
            'specs.*.group' => ['nullable', 'string', 'max:80'],
            'attributes' => ['sometimes', 'array'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['string', 'max:500'],
            'tags' => ['sometimes', 'array', 'max:15'],
            'tags.*' => ['string', 'max:40'],
            'relatedSlugs' => ['sometimes', 'array', 'max:8'],
            'relatedSlugs.*' => ['string', 'max:120'],
            'videoUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
            'publishedAt' => ['sometimes', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'slug.prohibited' => 'نامک محصول پس از ایجاد قابل تغییر نیست.',
            'slug.regex' => 'نامک فقط می‌تواند شامل حروف کوچک لاتین، رقم و خط تیره باشد.',
            'slug.unique' => 'این نامک قبلاً استفاده شده است.',
            'categorySlug.exists' => 'دسته‌بندی انتخاب‌شده وجود ندارد.',
            'brandSlug.exists' => 'برند انتخاب‌شده وجود ندارد.',
        ];
    }

    /**
     * فقط کلیدهای ارسال‌شده را به ستون‌های دیتابیس نگاشت می‌کند تا PATCH
     * ناخواسته فیلدهای دیگر را پاک نکند.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $map = [
            'slug' => 'slug',
            'name' => 'name',
            'categorySlug' => 'category_slug',
            'brandSlug' => 'brand_slug',
            'price' => 'price',
            'compareAtPrice' => 'compare_at_price',
            'stock' => 'stock',
            'shortDescription' => 'short_description',
            'description' => 'description',
            'highlights' => 'highlights',
            'specs' => 'specs',
            'attributes' => 'attributes',
            'images' => 'images',
            'tags' => 'tags',
            'relatedSlugs' => 'related_slugs',
            'videoUrl' => 'video_url',
            'isActive' => 'is_active',
            'publishedAt' => 'published_at',
        ];

        $columns = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $columns[$column] = $data[$key];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, string>|null  $categoryNames
     * @param  array<string, string>|null  $brandNames
     */
    private function serialize(Product $p, ?array $categoryNames = null, ?array $brandNames = null): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'category' => $p->category_slug,
            'categoryName' => $categoryNames[$p->category_slug]
                ?? Category::query()->where('slug', $p->category_slug)->value('name')
                ?? $p->category_slug,
            'brand' => $p->brand_slug,
            'brandName' => $brandNames[$p->brand_slug]
                ?? Brand::query()->where('slug', $p->brand_slug)->value('name')
                ?? $p->brand_slug,
            'price' => $p->price,
            'compareAtPrice' => $p->compare_at_price,
            'stock' => $p->stock,
            'rating' => $p->rating,
            'reviewCount' => $p->review_count,
            'shortDescription' => $p->short_description,
            'description' => $p->description,
            'highlights' => $p->highlights ?: [],
            'specs' => $p->specs ?: [],
            'attributes' => $p->attributes ?: [],
            'images' => $p->images ?: [],
            'tags' => $p->tags ?: [],
            'relatedSlugs' => $p->related_slugs ?: [],
            'videoUrl' => $p->video_url,
            'isActive' => (bool) $p->is_active,
            'publishedAt' => optional($p->published_at)->toDateString(),
            'updatedAt' => optional($p->updated_at)->toIso8601String(),
        ];
    }
}
