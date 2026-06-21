<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BannerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'array', 'size:4'],
            'image.*' => ['required', 'string', 'max:2048', 'not_regex:/\.\./'],
            'video' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'banner_title' => ['nullable', 'string', 'max:255'],
            'banner_description' => ['nullable', 'string'],
            'video_title' => ['nullable', 'string', 'max:255'],
            'video_description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Exactly 4 banner images are required',
            'image.size' => 'Exactly 4 banner images are required',
            'image.*.not_regex' => 'Banner image must be a valid file path',
            'video.not_regex' => 'Banner video must be a valid file path',
        ];
    }
}
