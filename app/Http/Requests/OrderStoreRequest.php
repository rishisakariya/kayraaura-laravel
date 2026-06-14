<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authenticated users can create orders
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'checkout_type' => ['required', Rule::in(['cart', 'buy_now'])],
            'address_id' => 'required|integer|exists:user_addresses,id',
            'payment_method' => ['required', Rule::in(['cod', 'online'])],
            'cod_otp' => 'required_if:payment_method,cod|string|digits:6',
            'product_size_id' => 'required_if:checkout_type,buy_now|integer|exists:product_sizes,id',
            'quantity' => 'required_if:checkout_type,buy_now|integer|min:1|max:99',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'checkout_type.required' => 'Checkout type is required',
            'address_id.required' => 'Address is required',
            'payment_method.required' => 'Payment method is required',
            'cod_otp.required_if' => 'COD OTP is required for COD orders',
            'product_size_id.required_if' => 'Product size ID is required for buy now checkout',
            'quantity.required_if' => 'Quantity is required for buy now checkout',
        ];
    }
}
