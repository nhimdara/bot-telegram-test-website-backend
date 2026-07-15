<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\UploadedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with('category')
            ->when($request->integer('category_id'), fn ($query, $id) => $query->where('category_id', $id))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate(25);

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        validator($data, ['slug' => ['required', 'unique:products,slug']])->validate();

        $product = DB::transaction(function () use ($request, $data) {
            $this->attachUploadedImage($request, $data);

            return Product::query()->create($data);
        });

        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load('category'));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);
        DB::transaction(function () use ($request, $data, $product) {
            $previousImage = $product->uploaded_image_id;
            $this->attachUploadedImage($request, $data);
            $product->update($data);
            if (isset($data['uploaded_image_id']) && $previousImage) {
                UploadedImage::query()->whereKey($previousImage)->delete();
            }
        });

        return response()->json($product->fresh()->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        abort_if(OrderItem::query()->where('product_id', $product->id)->exists(), 422, 'Products used in orders cannot be deleted. Set stock to zero instead.');
        $uploadedImage = $product->uploaded_image_id;
        $product->delete();
        if ($uploadedImage) {
            UploadedImage::query()->whereKey($uploadedImage)->delete();
        }

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $presence = $product ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => [$presence, 'integer', 'exists:categories,id'],
            'name' => [$presence, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('products')->ignore($product)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'price' => [$presence, 'numeric', 'min:0', 'max:99999999.99'],
            'stock' => [$presence, 'integer', 'min:0'],
        ]);
    }

    private function attachUploadedImage(Request $request, array &$data): void
    {
        unset($data['image']);
        if (! $request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');
        $image = UploadedImage::query()->create([
            'id' => (string) Str::uuid(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'data' => file_get_contents($file->getRealPath()),
        ]);
        $data['uploaded_image_id'] = $image->id;
        $data['image_url'] = route('images.show', $image);
    }
}
