<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['days' => ['sometimes', 'integer', Rule::in([7, 30, 90])]]);
        $days = (int) $request->input('days', 30);
        $end = now();
        $start = $end->copy()->startOfDay()->subDays($days - 1);
        $previousStart = $start->copy()->subDays($days);
        $admin = $request->user();
        $permissions = [
            'orders' => $admin->hasPermission('orders.index'),
            'products' => $admin->hasPermission('products.index'),
            'customers' => $admin->hasPermission('users.index'),
        ];
        $metrics = [];
        foreach (['products' => Product::class, 'customers' => User::class] as $key => $model) {
            if ($permissions[$key]) {
                $metrics[$key] = $this->metric($model::where('created_at', '<=', $end)->count(), $model::where('created_at', '<', $start)->count(), 'period_start');
            }
        }
        $data = [
            'period' => ['days' => $days, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => config('app.timezone'), 'currency' => 'USD'],
            'permissions' => $permissions, 'metrics' => $metrics,
            'sales' => null, 'statuses' => null, 'recent_orders' => null, 'top_products' => null,
        ];
        if ($permissions['orders']) {
            $orders = Order::whereBetween('created_at', [$start, $end]);
            $previous = Order::where('created_at', '>=', $previousStart)->where('created_at', '<', $start);
            $current = $this->orderTotals($orders);
            $prior = $this->orderTotals($previous);
            $data['metrics']['orders'] = $this->metric((int) $current->orders, (int) $prior->orders);
            $data['metrics']['revenue'] = $this->metric((float) $current->revenue, (float) $prior->revenue);
            $daily = (clone $orders)->selectRaw("DATE(created_at) as day, COUNT(*) as orders, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN total_amount ELSE 0 END), 0) as revenue")
                ->groupByRaw('DATE(created_at)')->get()->keyBy('day');
            $series = [];
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $day = $date->toDateString();
                $series[] = ['date' => $day, 'orders' => (int) ($daily[$day]->orders ?? 0), 'revenue' => round((float) ($daily[$day]->revenue ?? 0), 2)];
            }
            $data['sales'] = ['series' => $series, 'paid_orders' => (int) $current->paid_orders, 'average_order_value' => $current->paid_orders ? round($current->revenue / $current->paid_orders, 2) : 0];
            $statusCounts = (clone $orders)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            $data['statuses'] = collect(['delivered', 'processing', 'confirmed', 'shipped', 'pending', 'cancelled'])->map(fn ($status) => ['status' => $status, 'count' => (int) ($statusCounts[$status] ?? 0)])->values();
            $data['recent_orders'] = (clone $orders)->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(5)
                ->get(['id', 'order_number', 'user_id', 'total_amount', 'status', 'created_at'])->map(fn ($order) => [
                    'id' => $order->id, 'order_number' => $order->order_number, 'customer' => $order->user?->name ?? 'Deleted customer',
                    'amount' => (float) $order->total_amount, 'status' => $order->status, 'date' => $order->created_at->toDateString(),
                ]);
            if ($permissions['products']) {
                $rows = DB::table('order_items')->whereIn('order_id', (clone $orders)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->select('id'))
                    ->selectRaw('product_id, MAX(product_name) as name, SUM(quantity) as quantity, SUM(subtotal) as revenue')
                    ->groupBy('product_id')->groupByRaw("CASE WHEN product_id IS NULL THEN product_name ELSE '' END")
                    ->orderByDesc('quantity')->orderByDesc('revenue')->limit(5)->get();
                $products = Product::whereIn('id', $rows->pluck('product_id')->filter())->get()->keyBy('id');
                $data['top_products'] = $rows->map(function ($row) use ($products) {
                    $product = $products->get($row->product_id);
                    return ['id' => $product?->id, 'name' => $product?->name ?? $row->name, 'image' => $product?->cover_thumbnail_url ?? $product?->cover_image_url,
                        'quantity' => (int) $row->quantity, 'revenue' => round((float) $row->revenue, 2)];
                });
            }
        }
        return response()->json(['data' => $data]);
    }

    private function orderTotals($query)
    {
        return (clone $query)->selectRaw("COUNT(*) as orders, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN total_amount ELSE 0 END), 0) as revenue, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN 1 ELSE 0 END), 0) as paid_orders")->first();
    }

    private function metric(int|float $current, int|float $previous, string $comparison = 'previous_period'): array
    {
        return ['value' => $current, 'previous' => $previous, 'change_percent' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : ($current > 0 ? null : 0), 'comparison' => $comparison];
    }
}
