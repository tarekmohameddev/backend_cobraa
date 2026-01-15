<?php
declare(strict_types=1);

namespace App\Traits;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Str;
use Throwable;

trait SetTranslations
{
    /**
     * Most translation tables use VARCHAR(191) for title.
     * Keep a safe global cap to prevent SQLSTATE[22001] for long external titles.
     */
    private int $translationTitleMaxLength = 191;

    /**
     * Slug columns are added as string() (default 255).
     */
    private int $slugMaxLength = 255;

    /**
     * @param Model $model Все модели у которых есть таблица $model_translations
     * @param array $data
     * @return void
     */
    public function setTranslations(Model $model, array $data): void
    {
        try {
            /** @var Category $model */
            if (is_array(data_get($data, 'title'))) {
                $model->translations()->delete();
            }

            $defaultLocale = Language::whereDefault(1)->first()?->locale;

            $title = (array)data_get($data, 'title');

            // Normalize + cap translation titles to avoid DB truncation errors.
            foreach ($title as $locale => $value) {
                if (!is_string($value)) {
                    continue;
                }

                // Remove control characters (tabs/newlines), collapse whitespace, trim.
                $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
                $normalized = preg_replace('/\s+/u', ' ', (string)$normalized);
                $normalized = trim((string)$normalized);

                // Hard truncate to safe length without ellipsis.
                $normalized = Str::limit($normalized, $this->translationTitleMaxLength, '');

                $title[$locale] = $normalized;
            }

            try {
                $this->setSlug($model, $title, $defaultLocale);
            } catch (Throwable) {}

            foreach ($title as $index => $value) {

                $model->translations()->create([
                    'title'       => $value,
                    'locale'      => $index,
                    'description' => @$data['description'][$index]  ?? '',
                    'address'     => @$data['address'][$index]      ?? '',
                    'button_text' => @$data['button_text'][$index]  ?? '',
                ]);

            }

        } catch (Throwable $e) {
            $this->error($e);
        }
    }

    /**
     * Генерируем slug для определенных моделей заданных в переменной $classes внутри функции
     * @param Model $model
     * @param array $title
     * @param string $defaultLocale
     * @return void
     */
    public function setSlug(Model $model, array $title, string $defaultLocale): void
    {
        $classes = [
            Shop::class     => Shop::class,
            Category::class => Category::class,
            Brand::class    => Brand::class,
            Product::class  => Product::class,
        ];

        if (in_array(get_class($model), $classes) && isset($title[$defaultLocale])) {

            /** и другие классы в переменной $classes @var Shop $model */
            $idSuffix = "-$model->id";
            $baseSlug = Str::slug((string)$title[$defaultLocale], language: $defaultLocale);

            // Ensure final slug fits the DB column (default string() => 255).
            $maxBaseLen = max(0, $this->slugMaxLength - strlen($idSuffix));
            if (strlen($baseSlug) > $maxBaseLen) {
                $baseSlug = substr($baseSlug, 0, $maxBaseLen);
                $baseSlug = rtrim($baseSlug, '-');
            }

            $final = ($baseSlug !== '' ? $baseSlug : 'item') . $idSuffix;

            $model->update([
                'slug' => $final,
            ]);

        }
    }
}
