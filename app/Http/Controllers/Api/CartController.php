<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->cartResponse($this->userCart($request));
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cart = $this->userCart($request);
        $product = Product::query()->findOrFail($data['product_id']);
        $item = $cart->items()->where('product_id', $product->id)->first();
        $newQuantity = ($item?->quantity ?? 0) + $data['quantity'];
        $this->ensureStock($product, $newQuantity);

        if ($item) {
            $item->update(['quantity' => $newQuantity]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $newQuantity,
            ]);
        }

        return $this->cartResponse($cart, 201);
    }

    public function updateItem(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->ensureOwnership($request, $cartItem);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);
        $this->ensureStock($cartItem->product, $data['quantity']);
        $cartItem->update(['quantity' => $data['quantity']]);

        return $this->cartResponse($cartItem->cart);
    }

    public function removeItem(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->ensureOwnership($request, $cartItem);
        $cart = $cartItem->cart;
        $cartItem->delete();

        return $this->cartResponse($cart);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->userCart($request);
        $cart->items()->delete();

        return response()->json(null, 204);
    }

    private function userCart(Request $request): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $request->user()->id]);
    }

    private function ensureOwnership(Request $request, CartItem $cartItem): void
    {
        abort_unless($cartItem->cart()->where('user_id', $request->user()->id)->exists(), 404);
    }

    private function ensureStock(Product $product, int $quantity): void
    {
        if ($quantity > $product->stock) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$product->stock} item(s) are available.",
            ]);
        }
    }

    private function cartResponse(Cart $cart, int $status = 200): JsonResponse
    {
        $cart->load('items.product.category');
        $subtotal = $cart->items->sum(fn (CartItem $item) => (float) $item->product->price * $item->quantity);

        return response()->json([
            'id' => $cart->id,
            'items' => $cart->items,
            'total' => number_format($subtotal, 2, '.', ''),
        ], $status);
    }
}
