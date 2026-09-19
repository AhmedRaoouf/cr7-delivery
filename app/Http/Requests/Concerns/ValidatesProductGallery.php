<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Support\Media\ProductGallery;
use Illuminate\Validation\Validator;

/**
 * Batch 72 — the gallery fields of a product write, shared by the three product requests.
 *
 * `gallery[]` are uploaded files, `gallery_media[]` are picker refs (`asset:N` / `own:<sha1>`,
 * resolved and authorised later by `MediaPicker`, which answers `422 media_not_allowed`),
 * `expected_version` is the optimistic-lock token from `gallery_version`. Together they may
 * name at most {@see Product::GALLERY_MAX} pictures — a limit the request states so that the
 * dashboard's form can show the same number the server enforces.
 *
 * Declared as `after()` rather than `withValidator()`: the requests here do not define
 * either, and a `prepareForValidation()`-style silent override is exactly what the
 * project's memory warns about.
 */
trait ValidatesProductGallery
{
    /** @return array<string, list<mixed>> */
    protected function galleryRules(): array
    {
        return [
            'gallery' => ['nullable', 'array', 'max:'.Product::GALLERY_MAX],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'gallery_media' => ['nullable', 'array', 'max:'.Product::GALLERY_MAX],
            'gallery_media.*' => ['string', 'max:2048'],
            'expected_version' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = $this->hasFile('gallery') ? count((array) $this->file('gallery')) : 0;
            $refs = count(array_unique(array_filter((array) $this->input('gallery_media', []), fn ($ref) => is_string($ref) && $ref !== '')));

            if ($files + $refs > Product::GALLERY_MAX && ! $validator->errors()->has('gallery')) {
                $validator->errors()->add('gallery', ProductGallery::tooMany()->errors()['gallery'][0]);
            }
        }];
    }
}
