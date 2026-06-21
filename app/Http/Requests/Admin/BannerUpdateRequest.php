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
        $imageRules = ['required', 'string', 'max:2048', 'not_regex:/\.\./'];

        return [
            'image1' => $imageRules,
            'image2' => $imageRules,
            'image3' => $imageRules,
            'image4' => $imageRules,
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
            'image1.required' => 'Banner image 1 is required',
            'image2.required' => 'Banner image 2 is required',
            'image3.required' => 'Banner image 3 is required',
            'image4.required' => 'Banner image 4 is required',
            'image1.not_regex' => 'Banner image 1 must be a valid file path',
            'image2.not_regex' => 'Banner image 2 must be a valid file path',
            'image3.not_regex' => 'Banner image 3 must be a valid file path',
            'image4.not_regex' => 'Banner image 4 must be a valid file path',
            'video.not_regex' => 'Banner video must be a valid file path',
        ];
    }
}
