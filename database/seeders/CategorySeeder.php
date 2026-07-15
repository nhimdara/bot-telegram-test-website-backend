<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Electronics', 'slug' => 'electronics'],
            ['name' => 'Accessories', 'slug' => 'accessories'],
            ['name' => 'Home & Living', 'slug' => 'home-living'],
            ['name' => 'Fashion', 'slug' => 'fashion'],
            ['name' => 'Sports & Outdoors', 'slug' => 'sports-outdoors'],
        ] as $category) {
            Category::query()->updateOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
