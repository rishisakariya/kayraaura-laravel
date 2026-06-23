<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Services\OrderRefundCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class OrderReturnPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->order();

            if (!$order || $validator->errors()->isNotEmpty()) {
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

    private function order(): ?Order
    {
        return Order::where('user_id', Auth::id())
            ->with('orderItems')
            ->find($this->route('id'));
    }
}
