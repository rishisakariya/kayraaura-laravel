<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Services\OrderRefundCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class OrderReturnRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'product_images' => ['required', 'array', 'min:1', 'max:3'],
            'product_images.0' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'product_images.1' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'product_images.2' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];

        if ($this->order()?->payment_method === 'cod') {
            $rules['full_name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email', 'max:255'];
            $rules['mobile'] = ['required', 'string', 'max:20'];
            $rules['upi_id'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->order();

            if (!$order) {
                return;
            }

            if ($order->payment_method !== 'cod') {
                foreach (['full_name', 'email', 'mobile', 'upi_id'] as $field) {
                    if ($this->filled($field)) {
                        $validator->errors()->add(
                            $field,
                            'This field is not required for online payment returns.'
                        );
                    }
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $calculator = app(OrderRefundCalculator::class);
                $quantities = $calculator->mapReturnItemsToQuantities($this->input('items', []));
                $calculator->assertValidReturnQuantities($order, $quantities);
            } catch (\DomainException $e) {
                $validator->errors()->add('items', $e->getMessage());
            }
        });
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Return reason is required',
            'reason.max' => 'Return reason must not exceed 500 characters',
            'items.required' => 'At least one product must be selected for return',
            'items.min' => 'At least one product must be selected for return',
            'items.*.order_item_id.required' => 'Each return item must include an order item id',
            'items.*.quantity.required' => 'Return quantity is required for each selected product',
            'items.*.quantity.min' => 'Return quantity must be at least 1',
            'product_images.required' => 'At least one product image is required',
            'product_images.min' => 'At least one product image is required',
            'product_images.max' => 'You can upload a maximum of 3 product images',
            'product_images.0.required' => 'At least one product image is required',
            'product_images.0.image' => 'The first product image must be a valid image file',
            'product_images.0.mimes' => 'The first product image must be a JPEG, PNG, or WebP file',
            'product_images.0.max' => 'The first product image must not exceed 5 MB',
            'product_images.1.image' => 'The second product image must be a valid image file',
            'product_images.1.mimes' => 'The second product image must be a JPEG, PNG, or WebP file',
            'product_images.1.max' => 'The second product image must not exceed 5 MB',
            'product_images.2.image' => 'The third product image must be a valid image file',
            'product_images.2.mimes' => 'The third product image must be a JPEG, PNG, or WebP file',
            'product_images.2.max' => 'The third product image must not exceed 5 MB',
            'full_name.required' => 'Full name is required for COD returns',
            'email.required' => 'Email address is required for COD returns',
            'email.email' => 'Please enter a valid email address',
            'mobile.required' => 'Mobile number is required for COD returns',
            'upi_id.required' => 'UPI ID is required for COD returns',
        ];
    }

    private function order(): ?Order
    {
        return Order::where('user_id', Auth::id())
            ->with('orderItems')
            ->find($this->route('id'));
    }
}
