<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checkout_type' => ['required', Rule::in(['cart', 'buy_now'])],
            'address_id' => 'required|integer|exists:user_addresses,id',
            'payment_method' => ['required', Rule::in(['cod', 'online'])],
            'product_size_id' => 'required_if:checkout_type,buy_now|integer|exists:product_sizes,id',
            'quantity' => 'required_if:checkout_type,buy_now|integer|min:1|max:99',
            'coupon_code' => ['nullable', 'string', 'size:6'],
        ];
    }
}
