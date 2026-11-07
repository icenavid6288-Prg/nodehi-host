<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * بررسی و تأیید نظرهای مشتریان.
 *
 * امتیاز و تعداد نظر هر محصول پس از هر تغییر وضعیت از روی نظرهای تأییدشده
 * دوباره محاسبه می‌شود تا اطلاعات کارت محصول با محتوای واقعی هم‌خوان بماند.
 */
class AdminReviewController extends AdminController
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    public function index(Request $request): JsonResponse
    {
        $query = Review::query()->with('user:id,phone,first_name,last_name');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('author', 'like', "%{$q}%")
                    ->orWhere('title', 'like', "%{$q}%")
                    ->orWhere('body', 'like', "%{$q}%")
                    ->orWhere('product_slug', 'like', "%{$q}%");
            });
        }

        if (($status = (string) $request->query('status', '')) && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($slug = trim((string) $request->query('productSlug', ''))) {
            $query->where('product_slug', $slug);
        }

        if ($rating = (int) $request->query('rating', 0)) {
            if ($rating >= 1 && $rating <= 5) {
                $query->where('rating', $rating);
            }
        }

        $total = (clone $query)->count();
        $perPage = $this->perPage($request);
        $page = $this->page($request);

        $reviews = $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $productNames = Product::query()
            ->whereIn('slug', $reviews->pluck('product_slug')->unique())
            ->pluck('name', 'slug');

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'items' => $reviews->map(fn (Review $r) => $this->serialize($r, $productNames->all()))->all(),
        ]);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
        ]);

        $previous = $review->status;
        $review->update(['status' => $data['status']]);
        $this->syncProductStats($review->product_slug);

        $this->audit($request, 'review.moderated', 'review', $review->id, [
            'product' => $review->product_slug,
            'from' => $previous,
            'to' => $review->status,
        ]);

        return response()->json([
            'ok' => true,
            'review' => $this->serialize($review->fresh()->load('user:id,phone,first_name,last_name')),
        ]);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        $slug = $review->product_slug;
        $review->delete();
        $this->syncProductStats($slug);

        $this->audit($request, 'review.deleted', 'review', $review->id, ['product' => $slug]);

        return response()->json(['ok' => true, 'message' => 'نظر حذف شد.']);
    }

    private function syncProductStats(string $slug): void
    {
        $stats = Review::query()
            ->where('product_slug', $slug)
            ->where('status', 'approved')
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $total = (int) ($stats->total ?? 0);

        Product::query()->where('slug', $slug)->update([
            'review_count' => $total,
            'rating' => $total > 0 ? round((float) $stats->average, 1) : 0,
        ]);
    }

    /**
     * @param  array<string, string>  $productNames
     */
    private function serialize(Review $r, array $productNames = []): array
    {
        return [
            'id' => $r->id,
            'productSlug' => $r->product_slug,
            'productName' => $productNames[$r->product_slug]
                ?? Product::query()->where('slug', $r->product_slug)->value('name')
                ?? $r->product_slug,
            'author' => $r->author,
            'userPhone' => $r->user?->phone,
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'status' => $r->status,
            'createdAt' => optional($r->created_at)->toIso8601String(),
        ];
    }
}
