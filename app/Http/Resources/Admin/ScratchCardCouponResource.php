<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScratchCardCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discount_percent' => $this->discount_percent,
            'discount_amount' => $this->discount_amount !== null ? (float) $this->discount_amount : null,
            'is_redeemed' => $this->is_redeemed,
            'redeemed_at' => $this->redeemed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ];
            }),
            'order' => $this->whenLoaded('order', function () {
                if (!$this->order) {
                    return null;
                }

                return [
                    'id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                    'total_amount' => (float) $this->order->total_amount,
                    'discount_amount' => (float) ($this->order->discount_amount ?? 0),
                    'status' => $this->order->status,
                    'payment_status' => $this->order->payment_status,
                ];
            }),
        ];
    }
}
