<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BannerStoreRequest extends FormRequest
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
        $isCreateRequest = $this->isMethod('post') && (int) $this->input('edit_value', 0) === 0;

        return [
            'edit_value' => 'nullable|integer|min:0',
            'image' => [$isCreateRequest ? 'required' : 'sometimes', 'string', 'max:2048', 'not_regex:/\.\./'],
            'banner_title' => 'nullable|string|max:255',
            'banner_description' => 'nullable|string',
            'video_title' => 'nullable|string|max:255',
            'video_description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'edit_value.integer' => 'Edit value must be a valid banner ID',
            'image.required' => 'Banner image is required',
            'image.not_regex' => 'Banner image must be a valid media path',
        ];
    }
}
