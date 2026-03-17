<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    public function index()
    {
        $products = Product::with(['category', 'stocks' => function($q) {
            $q->where('quantity', '>', 0)->orderBy('created_at', 'asc');
        }])->latest()->get();

        return response()->json($products);
    }

    public function store(Request $request, $productId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        $product = Product::findOrFail($productId);

        DB::transaction(function () use ($request, $product) {
            // Generate Kode: STK-YYYYMMDDHHMMSS-RANDOM
            $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

            ProductStock::create([
                'product_id' => $product->id,
                'batch_code' => $batchCode,
                'quantity' => $request->quantity,
                'initial_quantity' => $request->quantity
            ]);

            // Sync total stok di tabel produk utama
            $product->increment('stock', $request->quantity);
        });

        return response()->json(['message' => 'New stock batch added successfully.']);
    }
}
