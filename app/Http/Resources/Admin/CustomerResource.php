<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'mobile_number' => $this->phone,
            'role' => $this->role,
            'email_verified_at' => $this->email_verified_at?->format('Y-m-d H:i:s'),
            'banned_until' => $this->banned_until?->format('Y-m-d H:i:s'),
            'is_banned' => $this->isBanned(),
            'orders_count' => (int) ($this->orders_count ?? 0),
            'total_spent' => (float) ($this->total_spent ?? 0),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
