<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartUpdateQuantityRequest extends FormRequest
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
        return [
            'product_size_id' => 'required|integer|exists:product_sizes,id',
            'quantity' => 'required|integer|min:0|max:99',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'product_size_id.required' => 'Product size ID is required',
            'product_size_id.exists' => 'Selected product size does not exist',
            'quantity.required' => 'Quantity is required',
            'quantity.min' => 'Quantity cannot be negative',
            'quantity.max' => 'Quantity cannot exceed 99 items',
        ];
    }
}
