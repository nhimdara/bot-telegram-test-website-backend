<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ShopDemoSeeder extends Seeder
{
    public function run(): void
    {
        $faker = fake();
        $faker->seed(20260715);
        $products = Product::query()->get();

        for ($number = 1; $number <= 8; $number++) {
            $telegramId = (string) (900000000 + $number);
            User::query()->where('telegram_id', $telegramId)->delete();

            $user = User::query()->create([
                'telegram_id' => $telegramId,
                'name' => $faker->name(),
                'email' => "demo{$number}@telegram.local",
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            $cart = Cart::query()->create(['user_id' => $user->id]);
            foreach ($products->random($faker->numberBetween(1, 4)) as $product) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $faker->numberBetween(1, min(3, $product->stock)),
                ]);
            }

            for ($orderNumber = 0; $orderNumber < $faker->numberBetween(1, 3); $orderNumber++) {
                $status = $faker->randomElement(['pending', 'processing', 'completed', 'cancelled']);
                $orderProducts = $products->random($faker->numberBetween(1, 4));
                $orderLines = $orderProducts->map(fn (Product $product) => [
                    'product' => $product,
                    'quantity' => $faker->numberBetween(1, 3),
                ]);
                $total = $orderLines->sum(fn (array $line) => (float) $line['product']->price * $line['quantity']);

                $order = $user->orders()->create([
                    'address' => $faker->streetAddress().', '.$faker->city(),
                    'notes' => $faker->boolean(35) ? $faker->sentence() : null,
                    'status' => $status,
                    'total' => $total,
                    'created_at' => $faker->dateTimeBetween('-3 months'),
                ]);

                foreach ($orderLines as $line) {
                    $order->items()->create([
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'price' => $line['product']->price,
                    ]);
                }
            }
        }
    }
}
