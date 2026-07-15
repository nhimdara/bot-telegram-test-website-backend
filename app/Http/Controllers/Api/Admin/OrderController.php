<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['user:id,name,telegram_id', 'items.product', 'payment'])
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', $search)
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('telegram_id', 'like', '%'.$search.'%'));
                });
            })
            ->latest()
            ->paginate(25);

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load(['user:id,name,telegram_id,email', 'items.product', 'payment']));
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled'])],
        ]);
        $nextStatus = $data['status'];

        DB::transaction(function () use ($order, $nextStatus) {
            $locked = Order::query()->with(['items', 'payment'])->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'cancelled' && $nextStatus !== 'cancelled') {
                throw ValidationException::withMessages(['status' => 'Cancelled orders cannot be reopened.']);
            }
            if ($nextStatus === 'cancelled' && $locked->status !== 'cancelled') {
                if ($locked->status !== 'pending') {
                    throw ValidationException::withMessages(['status' => 'Only unpaid pending orders can be cancelled.']);
                }
                foreach ($locked->items as $item) {
                    Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
                }
                $locked->payment?->update(['status' => 'cancelled']);
            }
            if (in_array($nextStatus, ['paid', 'processing', 'shipped', 'completed'], true)
                && $locked->payment?->status !== 'paid') {
                throw ValidationException::withMessages(['status' => 'This order does not have a verified paid transaction.']);
            }
            $locked->update(['status' => $nextStatus]);
        });

        return response()->json($order->fresh()->load(['user:id,name,telegram_id', 'items.product', 'payment']));
    }
}
