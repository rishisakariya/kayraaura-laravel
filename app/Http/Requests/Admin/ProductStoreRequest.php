<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
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
            'track_stock' => 'nullable|boolean',
            'weight_grams' => 'nullable|integer|min:1|max:100000',
            'sizes' => [
                'nullable',
                'array',
                'required_if:edit_value,0',
                'min:1',
            ],
            'sizes.*.size_text' => 'required_with:sizes|string|max:50',
            'sizes.*.quantity' => 'required_with:sizes|integer|min:0',
            'sizes.*.price' => 'required_with:sizes|numeric|min:0|max:99999999.99',
            'image' => 'nullable|array|max:5',
            'image.*' => ['required', 'string', 'max:2048', 'not_regex:/\.\./'],
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
            'image.max' => 'Maximum 5 product images allowed',
            'image.*.not_regex' => 'Product image must be a valid media URL or path',
            'weight_grams.min' => 'Product weight must be at least 1 gram',

            'ideal_for.in' => 'ideal_for must be one of: men, woman, both',
        ];
    }
}
