<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
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

    protected function prepareForValidation(): void
    {
        if ($this->filled('image_url') && ! $this->filled('image')) {
            $this->merge(['image' => $this->input('image_url')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $categoryId = (int) $this->input('edit_value');
        $isUpdate = $categoryId > 0;

        $rules = [
            'edit_value' => 'required|integer|min:0',
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],
            'description' => 'nullable|string|max:1000',
            'image' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'image_url' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];

        if (! $isUpdate) {
            $rules['type'] = 'required|in:main,sub';

            if ($this->input('type') === 'main') {
                $rules['parent_id'] = 'nullable|prohibited';
            } elseif ($this->input('type') === 'sub') {
                $rules['parent_id'] = [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where('type', 'main'),
                ];
            }
        } else {
            $category = Category::find($categoryId);

            if ($category && $category->type === 'sub') {
                $rules['parent_id'] = [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where('type', 'main'),
                ];
            } else {
                $rules['parent_id'] = 'nullable|prohibited';
            }
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required',
            'slug.unique' => 'Slug must be unique',
            'type.required' => 'Category type is required',
            'type.in' => 'Category type must be main or sub',
            'parent_id.required' => 'Parent category is required for sub categories',
            'parent_id.prohibited' => 'Main categories cannot have a parent',
            'parent_id.exists' => 'Selected parent must be a main category',
            'edit_value.required' => 'Edit value is required',
            'image.not_regex' => 'Category image must be a valid media path',
        ];
    }
}
