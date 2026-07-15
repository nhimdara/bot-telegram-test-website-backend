<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::query()->withCount('products')->orderBy('name')->get());
    }

    public function show(Category $category)
    {
        return response()->json($category->load(['products' => fn ($query) => $query->orderBy('name')]));
    }
}
