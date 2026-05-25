<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Get a paginated list of products with search, filtering, and sorting
     * Example: GET /api/products?page=1&search=dental&category=1&brand=2&sort=product_name&direction=asc
     */
    public function index(Request $request)
    {
        $products = Product::with(['category', 'subCategory', 'brand', 'image'])
            ->when($request->name, fn($q) =>
                $q->where('product_name', 'LIKE', "%{$request->name}%")
            )
            ->when($request->category, fn($q) =>
                $q->where('id_category', $request->category)
            )
            ->when($request->sub_category, fn($q) =>
                $q->where('id_sub_category', $request->sub_category)
            )
            ->when($request->brand, fn($q) =>
                $q->where('id_brand', $request->brand)
            )
            ->when($request->status, fn($q) =>
                $q->where('status', $request->status)
            )
            ->when($request->visibility, fn($q) =>
                $q->where('visibility', $request->visibility)
            )
            ->orderBy('product_name')
            ->paginate($request->get('limit', 15))
            ->withQueryString();

        // Transform the products to match the required API format
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'slug' => $product->slug,
                'category' => $product->category ? $product->category->category : null,
                'sub_category' => $product->subCategory ? $product->subCategory->sub_category : null,
                'brand' => $product->brand ? $product->brand->brand : null,
                'price' => $product->show_price ? $product->price : null,
                'stock_quantity' => $product->stock_quantity,
                'has_warranty' => $product->has_warranty,
                'warranty_duration_months' => $product->warranty_duration_months,
                'description' => $product->description,
                'reference' => $product->reference,
                'status' => $product->status,
                'visibility' => $product->visibility,
                'image_url' => $product->image ? asset('storage/' . $product->image->image_name) : null,
                'created_at' => $product->created_at ? $product->created_at->toISOString() : null
            ];
        });
        
        return response()->json([
            'products' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem()
            ],
            'links' => [
                'first' => $products->url(1),
                'last' => $products->url($products->lastPage()),
                'prev' => $products->previousPageUrl(),
                'next' => $products->nextPageUrl()
            ]
        ]);
    }


}
