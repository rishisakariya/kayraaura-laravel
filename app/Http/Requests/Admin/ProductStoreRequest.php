<?php

namespace App\Http\Requests\Admin;

use App\Support\MediaType;
use App\Support\PublicStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $hasImageKey = $this->exists('image');
        $hasVideoKey = $this->filled('video');

        if (!$hasImageKey && !$hasVideoKey) {
            return;
        }

        $images = $this->input('image');

        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }

        if (!is_array($images)) {
            $images = [];
        }

        if ($this->filled('video')) {
            $images[] = $this->input('video');
        }

        $normalized = [];

        foreach ($images as $image) {
            $path = $this->normalizeProductMediaInput($image);

            if ($path !== null) {
                $normalized[] = $path;
            }
        }

        $this->merge([
            'image' => array_values($normalized),
        ]);
    }

    private function normalizeProductMediaInput(mixed $value): ?string
    {
        if ($value instanceof UploadedFile) {
            $extension = strtolower($value->getClientOriginalExtension());

            if (!MediaType::isAllowedPath('placeholder.'.$extension)) {
                return null;
            }

            return PublicStorage::storeUploadedFile(
                $value,
                'products',
                now()->timestamp.'_'.Str::random(16).'.'.$extension,
            );
        }

        return PublicStorage::normalizeInput($value);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $productId = $this->input('edit_value');

        return [
            'edit_value' => 'required|integer|min:0',
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'description' => 'nullable|string|max:5000',
            'short_description' => 'nullable|string|max:1000',

            'brand' => 'nullable|string|max:255',
            'base_material' => 'nullable|string|max:255',
            'plating' => 'nullable|string|max:255',
            'gemstone' => 'nullable|string|max:255',
            'design' => 'nullable|string|max:255',
            'occasion' => 'nullable|string|max:255',
            'ideal_for' => ['nullable', 'string', 'in:men,woman,both'],
            'package_contents' => 'nullable|string|max:1000',

            'discount_percentage' => 'nullable|numeric|min:0|max:100',


            'category_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'is_collection' => 'nullable|boolean',
            'weight_grams' => 'required|integer|min:1|max:100000',
            'review_count' => 'nullable|integer|min:0|max:1000000',
            'sizes' => [
                'nullable',
                'array',
                'required_if:edit_value,0',
                'min:1',
            ],
            'sizes.*.size_id' => 'required_with:sizes|integer|distinct|exists:sizes,id',
            'sizes.*.quantity' => 'required_with:sizes|integer|min:0',
            'sizes.*.price' => 'required_with:sizes|numeric|min:0|max:99999999.99',
            'image' => 'nullable|array|max:5',
            'video' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'image.*' => [
                'required',
                'string',
                'max:2048',
                'not_regex:/\.\./',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) || !MediaType::isAllowedPath($value)) {
                        $fail('Each product media item must be a valid image or video file path.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required',
            'slug.unique' => 'Slug must be unique',

            'category_id.exists' => 'Selected category does not exist',
            'edit_value.required' => 'Edit value is required',
            'image.max' => 'Maximum 5 product media items allowed',
            'image.*.not_regex' => 'Product media must be a valid image or video URL or path',
            'weight_grams.min' => 'Product weight must be at least 1 gram',
            'sizes.*.size_id.required_with' => 'Size is required',
            'sizes.*.size_id.exists' => 'Selected size does not exist',
            'sizes.*.size_id.distinct' => 'Duplicate sizes are not allowed',

            'ideal_for.in' => 'ideal_for must be one of: men, woman, both',
        ];
    }
}
