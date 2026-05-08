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
            'price' => 'required|numeric|min:0|max:99999999.99',
            'sale_price' => 'nullable|numeric|min:0|max:99999999.99|lt:price',
            'cost_price' => 'nullable|numeric|min:0|max:99999999.99',
            'category_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'stock_quantity' => 'nullable|integer|min:0',
            'track_stock' => 'nullable|boolean',
            'images' => 'nullable|array|max:5',
            'images.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
            'existing_images' => 'nullable|array',
            'existing_images.*.id' => 'required|integer|exists:product_images,id',
            'existing_images.*.alt_text' => 'nullable|string|max:255',
            'existing_images.*.sort_order' => 'nullable|integer|min:0',
            'existing_images.*.is_primary' => 'nullable|boolean',
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
            'price.required' => 'Price is required',
            'sale_price.lt' => 'Sale price must be less than regular price',
            'category_id.exists' => 'Selected category does not exist',
            'images.max' => 'Maximum 5 images allowed',
            'images.*.image' => 'All files must be images',
            'images.*.mimes' => 'Images must be jpeg, jpg, png, or webp format',
            'images.*.max' => 'Image size must not exceed 2MB',
            'edit_value.required' => 'Edit value is required',
        ];
    }
}
