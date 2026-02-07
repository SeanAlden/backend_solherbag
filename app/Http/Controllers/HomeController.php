<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    /**
     * Mencari produk berdasarkan Nama atau Kode (Case Insensitive)
     * Digunakan untuk Hero Image & Double Image
     */
    public function getProductBySearch(Request $request)
    {
        $search = $request->query('query');

        $product = Product::where('status', 'active')
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search);
            })
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($product);
    }

    /**
     * Mengambil produk berdasarkan kode kategori (C001, C002)
     */
    public function getProductsByCategory($categoryCode)
    {
        $category = Category::where('code', $categoryCode)->first();

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $products = Product::where('category_id', $category->id)
            ->where('status', 'active')
            ->get();

        return response()->json($products);
    }
}
