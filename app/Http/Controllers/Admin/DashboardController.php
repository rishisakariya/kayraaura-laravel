<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const ORDER_STATUSES = [
        'pending',
        'processing',
        'shipped',
        'delivered',
        'return_requested',
        'returned',
        'cancelled',
        'manual_review',
    ];

    /**
     * Payment method distribution for pie chart.
     */
    public function paymentMethodDistribution(): JsonResponse
    {
        $rows = $this->countableOrdersQuery()
            ->select('payment_method', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->get();

        $total = (int) $rows->sum('count');

        $labels = [
            'cod' => 'Cash on Delivery',
            'online' => 'Online Payment',
        ];

        $data = $rows->map(function ($row) use ($total, $labels) {
            $count = (int) $row->count;

            return [
                'payment_method' => $row->payment_method,
                'label' => $labels[$row->payment_method] ?? ucfirst((string) $row->payment_method),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total_orders' => $total,
            ],
        ]);
    }

    /**
     * Total registered customers.
     */
    public function totalCustomers(): JsonResponse
    {
        $now = now();

        $total = User::where('role', 'customer')->count();
        $thisMonth = User::where('role', 'customer')
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();
        $lastMonthDate = $now->copy()->subMonth();
        $lastMonth = User::where('role', 'customer')
            ->whereYear('created_at', $lastMonthDate->year)
            ->whereMonth('created_at', $lastMonthDate->month)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'this_month' => $thisMonth,
                'last_month' => $lastMonth,
            ],
        ]);
    }

    /**
     * Total sales per month for the last 12 months.
     */
    public function monthlySales(): JsonResponse
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        $rows = Order::query()
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(total_amount) as total_sales, COUNT(*) as order_count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($row) => sprintf('%04d-%02d', $row->year, $row->month));

        $data = collect(range(0, 11))->map(function (int $offset) use ($startDate, $rows) {
            $monthDate = $startDate->copy()->addMonths($offset);
            $key = $monthDate->format('Y-m');
            $row = $rows->get($key);

            return [
                'month' => $key,
                'label' => $monthDate->format('M Y'),
                'total_sales' => round((float) ($row->total_sales ?? 0), 2),
                'order_count' => (int) ($row->order_count ?? 0),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Top 5 best selling products by quantity sold.
     */
    public function topProducts(): JsonResponse
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.total) as total_revenue')
            )
            ->groupBy('order_items.product_id')
            ->orderByDesc('quantity_sold')
            ->limit(5)
            ->get();

        $productIds = $rows->pluck('product_id')->filter()->unique()->values();
        $products = Product::with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            $primaryImage = $product?->images->first();

            return [
                'product_id' => (int) $row->product_id,
                'name' => $product?->name ?? $row->product_id,
                'slug' => $product?->slug,
                'image_url' => $primaryImage instanceof ProductImage ? $primaryImage->image_url : null,
                'quantity_sold' => (int) $row->quantity_sold,
                'total_revenue' => round((float) $row->total_revenue, 2),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Customer gender distribution for pie chart.
     */
    public function genderDistribution(): JsonResponse
    {
        $rows = User::where('role', 'customer')
            ->selectRaw("COALESCE(gender, 'unknown') as gender, COUNT(*) as count")
            ->groupBy('gender')
            ->get();

        $total = (int) $rows->sum('count');

        $labels = [
            'male' => 'Male',
            'female' => 'Female',
            'unknown' => 'Not Specified',
        ];

        $data = $rows->map(function ($row) use ($total, $labels) {
            $count = (int) $row->count;

            return [
                'gender' => $row->gender,
                'label' => $labels[$row->gender] ?? ucfirst((string) $row->gender),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total_customers' => $total,
            ],
        ]);
    }

    /**
     * Order status counts grouped by month for the last 12 months.
     */
    public function weeklyOrderStatus(): JsonResponse
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        $months = collect(range(0, 11))->map(function (int $offset) use ($startDate) {
            $start = $startDate->copy()->addMonths($offset);
            $end = $start->copy()->endOfMonth();

            $statusCounts = Order::query()
                ->whereBetween('created_at', [$start, $end])
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status');

            $statuses = collect(self::ORDER_STATUSES)->mapWithKeys(function (string $status) use ($statusCounts) {
                return [$status => (int) ($statusCounts[$status] ?? 0)];
            });

            $total = (int) $statuses->sum();

            return [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'month_start' => $start->toDateString(),
                'month_end' => $end->toDateString(),
                'statuses' => $statuses,
                'total' => $total,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $months,
        ]);
    }

    /**
     * Orders that should appear in dashboard analytics.
     */
    private function countableOrdersQuery()
    {
        return Order::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->where('payment_method', 'cod')
                    ->orWhere(function ($query) {
                        $query->where('payment_method', 'online')
                            ->where('payment_status', '!=', 'pending');
                    });
            });
    }
}
