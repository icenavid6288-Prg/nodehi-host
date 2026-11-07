<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\HeroSlide;
use App\Models\LegalPage;
use App\Models\LoyaltyInstallment;
use App\Models\LoyaltyStep;
use App\Models\LoyaltyTier;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * محتوای صفحات عمومی.
 *
 * متن‌ها در دیتابیس نگه داشته می‌شوند تا ویرایش آن‌ها بدون انتشار مجدد
 * فرانت‌اند انجام شود.
 */
class ContentController extends Controller
{
    public function articles(Request $request): JsonResponse
    {
        $query = Article::query()->active();
        $category = trim((string) $request->query('category', ''));

        if ($category !== '') {
            $query->where('category_slug', $category);
        }

        if ($limit = (int) $request->query('limit', 0)) {
            $query->limit(min($limit, 50));
        }

        $items = $query->orderByDesc('published_at')->orderBy('sort_order')->get();
        $counts = Article::query()
            ->active()
            ->selectRaw('category_slug, count(*) as total')
            ->groupBy('category_slug')
            ->pluck('total', 'category_slug');

        $categories = ArticleCategory::query()->orderBy('sort_order')->get()->map(fn (ArticleCategory $c) => [
            'slug' => $c->slug,
            'label' => $c->label,
            'count' => (int) ($counts[$c->slug] ?? 0),
        ]);

        return response()->json([
            'ok' => true,
            'total' => $items->count(),
            'category' => $category,
            'categories' => $categories,
            'items' => $items->map(fn (Article $a) => $this->serializeArticle($a))->all(),
        ]);
    }

    public function article(string $slug): JsonResponse
    {
        $article = Article::query()->active()->where('slug', $slug)->first();

        if (! $article) {
            return response()->json(['ok' => false, 'error' => 'مقاله یافت نشد.'], 404);
        }

        $related = Article::query()
            ->active()
            ->where('slug', '!=', $article->slug)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        $label = ArticleCategory::query()->where('slug', $article->category_slug)->value('label');

        return response()->json([
            'ok' => true,
            'article' => $this->serializeArticle($article, $label) + [
                'body' => $article->body,
            ],
            'related' => $related->map(fn (Article $a) => $this->serializeArticle($a))->all(),
            'relatedProducts' => $this->relatedProducts($article->keywords ?: []),
        ]);
    }

    public function faqs(): JsonResponse
    {
        $items = Faq::query()->active()->orderBy('sort_order')->get();
        $categories = FaqCategory::query()->orderBy('sort_order')->get()->map(fn (FaqCategory $c) => [
            'slug' => $c->slug,
            'label' => $c->label,
            'count' => $items->where('category_slug', $c->slug)->count(),
        ]);

        return response()->json([
            'ok' => true,
            'total' => $items->count(),
            'categories' => $categories,
            'items' => $items->map(fn (Faq $f) => [
                'id' => $f->id,
                'category' => $f->category_slug,
                'question' => $f->question,
                'answer' => $f->answer,
            ])->all(),
        ]);
    }

    public function legalIndex(): JsonResponse
    {
        $pages = LegalPage::query()->active()->orderBy('sort_order')->get(['slug', 'title']);

        return response()->json([
            'ok' => true,
            'items' => $pages->map(fn (LegalPage $p) => [
                'slug' => $p->slug,
                'title' => $p->title,
                'path' => '/'.$p->slug,
            ])->all(),
        ]);
    }

    public function legalPage(string $slug): JsonResponse
    {
        $page = LegalPage::query()->active()->where('slug', $slug)->first();

        if (! $page) {
            return response()->json(['ok' => false, 'error' => 'صفحه یافت نشد.'], 404);
        }

        return response()->json([
            'ok' => true,
            'page' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'intro' => $page->intro,
                'updatedAt' => optional($page->content_updated_at)->toDateString(),
                'sections' => $this->normalizeSections($page->sections ?: []),
            ],
        ]);
    }

    public function loyalty(): JsonResponse
    {
        $tiers = LoyaltyTier::query()->active()->orderBy('sort_order')->get()->map(fn (LoyaltyTier $t) => [
            'name' => $t->name,
            'requirement' => $t->requirement_label,
            'discount' => $t->discount_label,
            'points' => $t->points_label,
            'perks' => $t->perks,
        ]);

        $installments = LoyaltyInstallment::query()->active()->orderBy('months')->get()->map(fn (LoyaltyInstallment $i) => [
            'months' => $i->months,
            'label' => $i->label,
            'interest' => $i->interest_label,
            'note' => $i->note,
            'featured' => $i->is_featured,
        ]);

        $steps = LoyaltyStep::query()->orderBy('sort_order')->get()->map(fn (LoyaltyStep $s) => [
            'step' => $s->step,
            'title' => $s->title,
            'description' => $s->description,
        ]);

        return response()->json([
            'ok' => true,
            'tiers' => $tiers->all(),
            'installments' => $installments->all(),
            'steps' => $steps->all(),
        ]);
    }

    /**
     * لینک‌سازی داخلی مقاله به کالاهای موجود: کلمات کلیدی مقاله با نام و
     * تگ محصولات تطبیق داده می‌شوند تا خواننده مستقیم به صفحه خرید برود.
     */
    private function relatedProducts(array $keywords): array
    {
        $tokens = [];
        foreach ($keywords as $keyword) {
            foreach (preg_split('/\s+/u', (string) $keyword) ?: [] as $token) {
                $token = trim($token);
                // کلمات کوتاه و پرتکرار («خرید»، «به»، «و») نتیجه را بی‌ربط می‌کنند.
                if (mb_strlen($token) >= 4 && ! in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        if ($tokens === []) {
            return [];
        }

        $products = Product::query()
            ->active()
            ->where(function (Builder $query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('name', 'like', "%{$token}%")
                        ->orWhereJsonContains('tags', $token);
                }
            })
            ->orderByDesc('review_count')
            ->limit(4)
            ->get(['slug', 'name', 'price', 'images']);

        return $products->map(fn (Product $p) => [
            'slug' => $p->slug,
            'name' => $p->name,
            'price' => $p->price,
            'image' => $p->images[0] ?? null,
        ])->all();
    }

    /**
     * اسلایدهای هیروی صفحهٔ اصلی.
     *
     * فقط اسلایدهای فعال و به ترتیب نمایش برمی‌گردند؛ اگر هیچ اسلایدی نباشد
     * فرانت‌اند محتوای پیش‌فرض خودش را نشان می‌دهد.
     */
    public function heroSlides(): JsonResponse
    {
        $items = HeroSlide::query()->active()->ordered()->get()
            ->map(fn (HeroSlide $slide) => [
                'id' => $slide->id,
                'badge' => $slide->badge,
                'title' => $slide->title,
                'titleAccent' => $slide->title_accent,
                'text' => $slide->text,
                'primaryLabel' => $slide->primary_label,
                'primaryUrl' => $slide->primary_url,
                'secondaryLabel' => $slide->secondary_label,
                'secondaryUrl' => $slide->secondary_url,
                'features' => $slide->features ?: [],
                'image' => $slide->image,
                'theme' => $slide->theme,
            ])
            ->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    private function serializeArticle(Article $article, ?string $categoryLabel = null): array
    {
        return [
            'slug' => $article->slug,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'category' => $article->category_slug,
            'categoryLabel' => $categoryLabel
                ?? ArticleCategory::query()->where('slug', $article->category_slug)->value('label')
                ?? $article->category_slug,
            'author' => $article->author,
            'publishedAt' => optional($article->published_at)->toDateString(),
            'updatedAt' => optional($article->content_updated_at)->toDateString(),
            'readingMinutes' => $article->reading_minutes,
            'keywords' => $article->keywords ?: [],
        ];
    }

    /**
     * ساختار بخش‌های سند حقوقی را یکدست می‌کند تا کلاینت فقط با
     * کلیدهای همیشه‌حاضر کار کند.
     */
    private function normalizeSections(array $sections): array
    {
        return array_map(fn (array $section) => [
            'id' => (string) ($section['id'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'paragraphs' => array_values($section['paragraphs'] ?? []),
            'bullets' => array_values($section['bullets'] ?? []),
        ], $sections);
    }
}
