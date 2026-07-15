<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'payment'])
            ->latest()
            ->get();

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwnership($request, $order);

        return response()->json($order->load(['items.product', 'payment']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = DB::transaction(function () use ($request, $data) {
            $cart = Cart::query()->where('user_id', $request->user()->id)->lockForUpdate()->first();
            $items = $cart?->items()->with('product')->get() ?? collect();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Cart is empty.']);
            }

            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                if ($item->quantity > $product->stock) {
                    throw ValidationException::withMessages([
                        'cart' => "{$product->name} has only {$product->stock} item(s) available.",
                    ]);
                }
            }

            $total = $items->sum(fn ($item) => (float) $products[$item->product_id]->price * $item->quantity);
            $order = Order::query()->create([
                'user_id' => $request->user()->id,
                'address' => $data['address'],
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'total' => $total,
            ]);

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item->quantity,
                    'price' => $product->price,
                ]);
                $product->decrement('stock', $item->quantity);
            }

            $cart->items()->delete();

            return $order;
        });

        return response()->json($order->load(['items.product', 'payment']), 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwnership($request, $order);

        DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending orders can be cancelled.',
                ]);
            }

            $lockedOrder->load('items');
            foreach ($lockedOrder->items as $item) {
                Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
            }
            $lockedOrder->update(['status' => 'cancelled']);
            $lockedOrder->payment()->where('status', 'pending')->update(['status' => 'cancelled']);
        });

        return response()->json($order->fresh()->load(['items.product', 'payment']));
    }

    private function ensureOwnership(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}
