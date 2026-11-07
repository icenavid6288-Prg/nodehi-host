<?php

namespace App\Http\Controllers\Api;

use App\Models\HeroSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * مدیریت اسلایدهای هیروی صفحهٔ اصلی از پنل.
 *
 * متن، لینک و تصویر اسلایدها اینجا نگه‌داری می‌شوند تا تغییر کمپین نیازی به
 * انتشار دوبارهٔ سایت نداشته باشد. تصویر می‌تواند آدرس آماده باشد یا با
 * آپلود مستقیم روی دیسک `uploads` (داخل public/uploads) ذخیره شود؛ این دیسک
 * عمداً به symlink نیاز ندارد، چون بعضی هاست‌های اشتراکی آن را نمی‌سازند.
 */
class AdminHeroSlideController extends AdminController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'items' => HeroSlide::query()->ordered()->get()->map(fn (HeroSlide $s) => $this->serialize($s))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $slide = HeroSlide::create(array_merge($this->columns($data, $request), [
            'sort_order' => $data['sortOrder'] ?? ((int) HeroSlide::query()->max('sort_order') + 1),
            'is_active' => $data['isActive'] ?? true,
        ]));

        $this->audit($request, 'hero_slide.created', 'hero_slide', $slide->id, ['title' => $slide->title]);

        return response()->json(['ok' => true, 'slide' => $this->serialize($slide)], 201);
    }

    public function update(Request $request, HeroSlide $heroSlide): JsonResponse
    {
        $data = $this->validated($request, $heroSlide);

        $heroSlide->fill($this->columns($data, $request, $heroSlide));

        if (array_key_exists('sortOrder', $data)) {
            $heroSlide->sort_order = $data['sortOrder'];
        }
        if (array_key_exists('isActive', $data)) {
            $heroSlide->is_active = $data['isActive'];
        }

        $changed = array_keys($heroSlide->getDirty());
        $heroSlide->save();

        $this->audit($request, 'hero_slide.updated', 'hero_slide', $heroSlide->id, [
            'title' => $heroSlide->title,
            'fields' => $changed,
        ]);

        return response()->json(['ok' => true, 'slide' => $this->serialize($heroSlide->fresh())]);
    }

    public function destroy(Request $request, HeroSlide $heroSlide): JsonResponse
    {
        $id = $heroSlide->id;
        $title = $heroSlide->title;
        $image = $heroSlide->image;

        $heroSlide->delete();
        $this->deleteOrphanImage($image);

        $this->audit($request, 'hero_slide.deleted', 'hero_slide', $id, ['title' => $title]);

        return response()->json(['ok' => true, 'message' => 'اسلاید حذف شد.']);
    }

    /**
     * ترتیب تازهٔ اسلایدها را یک‌جا ذخیره می‌کند تا جابه‌جایی از پنل یک
     * درخواست باشد، نه یک ذخیره به‌ازای هر اسلاید.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:hero_slides,id'],
        ], [
            'ids.required' => 'فهرست اسلایدها خالی است.',
            'ids.*.exists' => 'یکی از اسلایدها پیدا نشد؛ صفحه را تازه کنید.',
        ]);

        foreach (array_values($data['ids']) as $position => $id) {
            HeroSlide::query()->whereKey($id)->update(['sort_order' => $position + 1]);
        }

        $this->audit($request, 'hero_slide.reordered', 'hero_slide', null, ['ids' => $data['ids']]);

        return response()->json([
            'ok' => true,
            'items' => HeroSlide::query()->ordered()->get()->map(fn (HeroSlide $s) => $this->serialize($s))->all(),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ], [
            'image.required' => 'تصویری انتخاب نشده است.',
            'image.image' => 'فایل انتخابی تصویر نیست.',
            'image.mimes' => 'فقط JPG، PNG یا WebP مجاز است.',
            'image.max' => 'حجم تصویر حداکثر ۳ مگابایت باشد.',
        ]);

        $path = (string) $request->file('image')->store('hero', 'uploads');

        // آدرس عمومی همان مسیر داخل public/uploads است؛ به `url` دیسک تکیه
        // نمی‌کنیم تا لینک ذخیره‌شده در دیتابیس به تنظیمات لحظه‌ای وابسته نباشد.
        $url = '/uploads/'.$path;

        $this->audit($request, 'hero_slide.image_uploaded', 'hero_slide', null, ['path' => $path]);

        return response()->json(['ok' => true, 'url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?HeroSlide $slide = null): array
    {
        $creating = $slide === null;
        $data = $request->validate([
            'badge' => ['sometimes', 'nullable', 'string', 'max:120'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'titleAccent' => ['sometimes', 'nullable', 'string', 'max:120'],
            'text' => [$creating ? 'required' : 'sometimes', 'string', 'max:400'],
            'primaryLabel' => [$creating ? 'required' : 'sometimes', 'string', 'max:40'],
            'primaryUrl' => [$creating ? 'required' : 'sometimes', 'string', 'max:200', 'regex:/^(\/|https?:\/\/)/'],
            'secondaryLabel' => ['sometimes', 'nullable', 'string', 'max:40'],
            'secondaryUrl' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^(\/|https?:\/\/)/'],
            'features' => ['sometimes', 'array', 'max:3'],
            'features.*' => ['string', 'max:60'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(\/|https?:\/\/)/'],
            'theme' => ['sometimes', Rule::in(HeroSlide::THEMES)],
            'sortOrder' => ['sometimes', 'integer', 'between:0,999'],
            'isActive' => ['sometimes', 'boolean'],
        ], [
            'title.required' => 'تیتر اسلاید الزامی است.',
            'text.required' => 'متن اسلاید الزامی است.',
            'primaryLabel.required' => 'برچسب دکمهٔ اصلی الزامی است.',
            'primaryUrl.required' => 'لینک دکمهٔ اصلی الزامی است.',
            'primaryUrl.regex' => 'لینک باید با / یا http(s):// شروع شود.',
            'secondaryUrl.regex' => 'لینک باید با / یا http(s):// شروع شود.',
            'secondaryUrl.required_pair' => 'برای دکمهٔ دوم، لینک هم لازم است.',
            'features.max' => 'حداکثر سه مزیت برای هر اسلاید مجاز است.',
            'image.regex' => 'آدرس تصویر باید با / یا http(s):// شروع شود.',
            'theme.in' => 'تم انتخابی معتبر نیست.',
        ]);

        // دکمهٔ دوم اگر برچسب داشته باشد باید لینک هم داشته باشد. این بررسی روی
        // مقدار نهایی (ادغام داده‌های تازه با رکورد فعلی) انجام می‌شود تا در
        // ویرایش جزئی هم ناسازگاری باقی نماند.
        $label = array_key_exists('secondaryLabel', $data)
            ? (string) ($data['secondaryLabel'] ?? '')
            : (string) ($slide?->secondary_label ?? '');
        $url = array_key_exists('secondaryUrl', $data)
            ? (string) ($data['secondaryUrl'] ?? '')
            : (string) ($slide?->secondary_url ?? '');

        if (trim($label) !== '' && trim($url) === '') {
            throw ValidationException::withMessages([
                'secondaryUrl' => 'برای دکمهٔ دوم، لینک هم لازم است.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data, Request $request, ?HeroSlide $slide = null): array
    {
        $columns = [];

        foreach ([
            'badge' => 'badge',
            'title' => 'title',
            'titleAccent' => 'title_accent',
            'text' => 'text',
            'primaryLabel' => 'primary_label',
            'primaryUrl' => 'primary_url',
            'secondaryLabel' => 'secondary_label',
            'secondaryUrl' => 'secondary_url',
            'image' => 'image',
            'theme' => 'theme',
        ] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $columns[$column] = $data[$key] === '' ? null : $data[$key];
            }
        }

        if (array_key_exists('features', $data)) {
            // خط‌های خالی فرم نباید به‌شکل آیتم خالی در API برگردند.
            $columns['features'] = array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $data['features']),
                static fn (string $item) => $item !== '',
            ));
        }

        // دکمهٔ دوم بدون برچسب، بی‌معنی است؛ هر دو با هم پاک شوند.
        if (array_key_exists('secondaryLabel', $data) && ($data['secondaryLabel'] === null || $data['secondaryLabel'] === '')) {
            $columns['secondary_label'] = null;
            $columns['secondary_url'] = null;
        }

        if ($slide && array_key_exists('image', $data) && $data['image'] === null && $slide->image) {
            $this->deleteOrphanImage($slide->image);
        }

        return $columns;
    }

    /** فایل آپلودی که دیگر استفاده نمی‌شود پاک می‌شود؛ آدرس‌های خارجی دست نمی‌خورند. */
    private function deleteOrphanImage(?string $image): void
    {
        if (! $image || ! str_starts_with($image, '/uploads/')) {
            return;
        }

        $relative = ltrim(str_replace('/uploads/', '', $image), '/');

        if (HeroSlide::query()->where('image', $image)->exists()) {
            return;
        }

        Storage::disk('uploads')->delete($relative);
    }

    private function serialize(HeroSlide $slide): array
    {
        return [
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
            'sortOrder' => (int) $slide->sort_order,
            'isActive' => (bool) $slide->is_active,
            'updatedAt' => optional($slide->updated_at)->toIso8601String(),
        ];
    }
}
