<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers for the admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_banned' => ['nullable', 'boolean'],
            'email_verified' => ['nullable', 'boolean'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date'],
            'min_orders' => ['nullable', 'integer', 'min:0'],
            'max_orders' => ['nullable', 'integer', 'min:0'],
            'min_spent' => ['nullable', 'numeric', 'min:0'],
            'max_spent' => ['nullable', 'numeric', 'min:0'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'name', 'email', 'orders_count', 'total_spent'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDirection = $validated['sort_direction'] ?? 'desc';

        $customers = User::query()
            ->where('role', 'customer')
            ->withCount('orders')
            ->withSum([
                'orders as total_spent' => fn ($query) => $query->where('payment_status', 'paid'),
            ], 'total_amount')
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', $search)
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_banned'), function ($query) use ($request) {
                if ($request->boolean('is_banned')) {
                    $query->whereNotNull('banned_until')
                        ->where('banned_until', '>', now());

                    return;
                }

                $query->where(function ($query) {
                    $query->whereNull('banned_until')
                        ->orWhere('banned_until', '<=', now());
                });
            })
            ->when($request->has('email_verified'), function ($query) use ($request) {
                $request->boolean('email_verified')
                    ? $query->whereNotNull('email_verified_at')
                    : $query->whereNull('email_verified_at');
            })
            ->when($request->input('registered_from'), function ($query, $date) {
                $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->input('registered_to'), function ($query, $date) {
                $query->whereDate('created_at', '<=', $date);
            })
            ->when($request->filled('min_orders'), function ($query) use ($request) {
                $query->has('orders', '>=', (int) $request->input('min_orders'));
            })
            ->when($request->filled('max_orders'), function ($query) use ($request) {
                $query->has('orders', '<=', (int) $request->input('max_orders'));
            })
            ->when($request->filled('min_spent'), function ($query) use ($request) {
                $query->whereRaw(
                    '(select coalesce(sum(total_amount), 0) from orders where orders.user_id = users.id and payment_status = ?) >= ?',
                    ['paid', $request->input('min_spent')]
                );
            })
            ->when($request->filled('max_spent'), function ($query) use ($request) {
                $query->whereRaw(
                    '(select coalesce(sum(total_amount), 0) from orders where orders.user_id = users.id and payment_status = ?) <= ?',
                    ['paid', $request->input('max_spent')]
                );
            })
            ->orderBy($sortBy, $sortDirection)
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => CustomerResource::collection($customers),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Ban a customer until the given date and time.
     */
    public function ban(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'banned_until' => ['required', 'date', 'after:now'],
        ]);

        $customer = User::where('role', 'customer')->findOrFail($id);

        $customer->forceFill([
            'banned_until' => $validated['banned_until'],
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Customer banned successfully',
            'data' => new CustomerResource($this->customerWithStats($customer->id)),
        ]);
    }

    /**
     * Remove a customer ban.
     */
    public function unban(string $id): JsonResponse
    {
        $customer = User::where('role', 'customer')->findOrFail($id);

        $customer->forceFill([
            'banned_until' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Customer unbanned successfully',
            'data' => new CustomerResource($this->customerWithStats($customer->id)),
        ]);
    }

    private function customerWithStats(int $id): User
    {
        return User::query()
            ->where('role', 'customer')
            ->withCount('orders')
            ->withSum([
                'orders as total_spent' => fn ($query) => $query->where('payment_status', 'paid'),
            ], 'total_amount')
            ->findOrFail($id);
    }
}
