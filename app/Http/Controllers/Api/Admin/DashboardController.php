<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'users' => User::query()->count(),
                'products' => Product::query()->count(),
                'categories' => Category::query()->count(),
                'orders' => Order::query()->count(),
                'pending_orders' => Order::query()->where('status', 'pending')->count(),
                'paid_orders' => Order::query()->whereIn('status', ['paid', 'processing', 'shipped', 'completed'])->count(),
            ],
            'revenue' => Payment::query()->where('status', 'paid')->sum('amount'),
            'low_stock' => Product::query()->with('category')->where('stock', '<=', 5)->orderBy('stock')->limit(8)->get(),
            'recent_orders' => Order::query()->with(['user:id,name,telegram_id', 'payment'])->latest()->limit(8)->get(),
        ]);
    }
}
