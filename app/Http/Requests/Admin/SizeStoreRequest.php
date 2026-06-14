<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SizeStoreRequest extends FormRequest
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
        $sizeId = $this->route('size') ?? $this->input('edit_value');

        return [
            'edit_value' => 'nullable|integer|min:0',
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('sizes', 'name')->ignore($sizeId),
            ],
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
            'name.required' => 'Size name is required',
            'name.unique' => 'Size name must be unique',
        ];
    }
}
