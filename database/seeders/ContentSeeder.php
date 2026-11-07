<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\LegalPage;
use App\Models\LoyaltyInstallment;
use App\Models\LoyaltyStep;
use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;

/**
 * محتوای صفحات عمومی — از فایل database/seeders/data/content.json خوانده
 * می‌شود تا متن‌ها بدون انتشار مجدد فرانت‌اند قابل تغییر باشند.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/content.json');
        $data = json_decode(file_get_contents($path), true) ?: [];

        foreach (($data['articleCategories'] ?? []) as $slug => $label) {
            ArticleCategory::updateOrCreate(['slug' => $slug], ['label' => $label, 'sort_order' => 0]);
        }

        foreach (($data['articles'] ?? []) as $i => $row) {
            Article::updateOrCreate(['slug' => $row['slug']], [
                'title' => $row['title'],
                'excerpt' => $row['excerpt'] ?? '',
                'body' => array_values($row['body'] ?? []),
                'category_slug' => $row['category'],
                'author' => $row['author'] ?? '',
                'published_at' => $row['publishedAt'],
                'content_updated_at' => $row['updatedAt'] ?? $row['publishedAt'],
                'reading_minutes' => (int) ($row['readingMinutes'] ?? 4),
                'keywords' => array_values($row['keywords'] ?? []),
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        foreach (($data['faqCategories'] ?? []) as $i => $row) {
            FaqCategory::updateOrCreate(['slug' => $row['slug']], [
                'label' => $row['label'],
                'sort_order' => $i,
            ]);
        }

        foreach (($data['faqs'] ?? []) as $i => $row) {
            Faq::updateOrCreate(
                ['category_slug' => $row['category'], 'question' => $row['question']],
                [
                    'answer' => $row['answer'],
                    'sort_order' => $i,
                    'is_active' => true,
                ]
            );
        }

        foreach (($data['legal'] ?? []) as $i => $row) {
            LegalPage::updateOrCreate(['slug' => $row['slug']], [
                'title' => $row['title'],
                'intro' => $row['intro'] ?? '',
                'sections' => array_values($row['sections'] ?? []),
                'content_updated_at' => $row['updatedAt'],
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        $loyalty = $data['loyalty'] ?? [];

        foreach (($loyalty['tiers'] ?? []) as $i => $row) {
            LoyaltyTier::updateOrCreate(['name' => $row['name']], [
                'requirement_label' => $row['requirement'] ?? '',
                'discount_label' => $row['discount'] ?? '',
                'points_label' => $row['points'] ?? '',
                'perks' => array_values($row['perks'] ?? []),
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        foreach (($loyalty['installments'] ?? []) as $i => $row) {
            LoyaltyInstallment::updateOrCreate(['months' => (int) $row['months']], [
                'label' => $row['label'],
                'interest_label' => $row['interest'] ?? '',
                'note' => $row['monthly'] ?? '',
                'is_featured' => $i === 1,
                'is_active' => true,
            ]);
        }

        foreach (($loyalty['steps'] ?? []) as $i => $row) {
            LoyaltyStep::updateOrCreate(['step' => (int) $row['step']], [
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'sort_order' => $i,
            ]);
        }
    }
}
