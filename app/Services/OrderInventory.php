<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderInventory
{
    public function release(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->with(['items', 'payment'])->lockForUpdate()->find($order->id);
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            foreach ($locked->items as $item) {
                Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
            }
            $locked->payment?->update(['status' => 'cancelled']);
            $locked->update(['status' => 'cancelled']);

            return true;
        });
    }

    public function releaseAbandonedForUser(int $userId, int $minutes = 30): int
    {
        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where(function ($query) {
                $query->whereDoesntHave('payment')
                    ->orWhereHas('payment', fn ($payment) => $payment->whereIn('status', ['expired', 'cancelled']));
            })
            ->get();

        return $orders->sum(fn (Order $order) => $this->release($order) ? 1 : 0);
    }
}
