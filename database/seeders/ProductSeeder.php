<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'slug');

        $products = [
            ['electronics', 'Starter Phone', 'starter-phone', 'A reliable entry-level smartphone with all-day battery life.', 199.99, 35],
            ['electronics', 'Compact Speaker', 'compact-speaker', 'A portable Bluetooth speaker with clear sound and USB-C charging.', 59.50, 42],
            ['electronics', 'Wireless Earbuds', 'wireless-earbuds', 'Comfortable noise-isolating earbuds with a pocket charging case.', 79.99, 28],
            ['electronics', 'Smart Watch', 'smart-watch', 'Fitness tracking, notifications, and a bright color display.', 129.00, 18],
            ['electronics', 'Power Bank 20000mAh', 'power-bank-20000', 'High-capacity fast-charging power bank with dual USB outputs.', 44.90, 55],

            ['accessories', 'Braided USB-C Cable', 'braided-usb-c-cable', 'Durable two-meter charging and data cable.', 12.99, 100],
            ['accessories', 'Laptop Sleeve', 'laptop-sleeve', 'Water-resistant padded sleeve for laptops up to 15 inches.', 24.50, 48],
            ['accessories', 'Minimal Wallet', 'minimal-wallet', 'Slim RFID-blocking wallet with six card slots.', 19.95, 60],
            ['accessories', 'Phone Stand', 'phone-stand', 'Adjustable aluminum desktop stand for phones and small tablets.', 15.75, 75],

            ['home-living', 'Ceramic Coffee Mug', 'ceramic-coffee-mug', 'Large matte-finish mug suitable for hot and cold drinks.', 14.00, 64],
            ['home-living', 'LED Desk Lamp', 'led-desk-lamp', 'Dimmable lamp with three color temperatures and touch controls.', 38.90, 26],
            ['home-living', 'Cotton Throw Pillow', 'cotton-throw-pillow', 'Soft removable cotton cover with a supportive inner cushion.', 22.00, 30],
            ['home-living', 'Aroma Diffuser', 'aroma-diffuser', 'Quiet ultrasonic diffuser with ambient lighting and auto shutoff.', 32.50, 24],

            ['fashion', 'Classic T-Shirt', 'classic-t-shirt', 'Breathable everyday cotton T-shirt with a relaxed fit.', 18.99, 80],
            ['fashion', 'Canvas Backpack', 'canvas-backpack', 'Everyday backpack with a padded laptop compartment.', 46.00, 33],
            ['fashion', 'Polarized Sunglasses', 'polarized-sunglasses', 'Lightweight UV400 sunglasses with polarized lenses.', 29.90, 45],
            ['fashion', 'Everyday Cap', 'everyday-cap', 'Adjustable washed-cotton cap with a curved brim.', 16.50, 50],

            ['sports-outdoors', 'Yoga Mat', 'yoga-mat', 'Non-slip cushioned exercise mat with a carrying strap.', 27.99, 36],
            ['sports-outdoors', 'Stainless Water Bottle', 'stainless-water-bottle', 'Insulated 750ml bottle that keeps drinks cold for 24 hours.', 23.50, 58],
            ['sports-outdoors', 'Resistance Band Set', 'resistance-band-set', 'Five resistance levels with handles, anchors, and travel pouch.', 21.00, 40],
            ['sports-outdoors', 'Compact Daypack', 'compact-daypack', 'Lightweight foldable 20-liter backpack for day trips.', 34.95, 29],
        ];

        foreach ($products as [$categorySlug, $name, $slug, $description, $price, $stock]) {
            Product::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'category_id' => $categories[$categorySlug],
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'stock' => $stock,
                ]
            );
        }
    }
}
