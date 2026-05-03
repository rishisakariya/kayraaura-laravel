<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryStoreRequest extends FormRequest
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
        $categoryId = $this->input('edit_value');

        return [
            'edit_value' => 'required|integer|min:0',
            'name' => 'required|string|max:255',
            'slug' => [
            'nullable',
            'string',
            'max:255',
            Rule::unique('categories', 'slug')->ignore($categoryId),
        ],
            'description' => 'nullable|string|max:1000',
            'image' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required',
            'slug.unique' => 'Slug must be unique',
            'parent_id.exists' => 'Selected parent category does not exist',
            'edit_value.required' => 'Edit value is required',
        ];
    }
}
