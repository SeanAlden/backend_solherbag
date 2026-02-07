<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $carts = Cart::with('product')->where('user_id', $request->user()->id)->latest()->get();
        return response()->json($carts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $product = Product::findOrFail($request->product_id);
        $user = $request->user();

        // Cari apakah produk sudah ada di keranjang user
        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        $newQuantity = $cartItem ? $cartItem->quantity + $request->quantity : $request->quantity;

        // VALIDASI STOK
        if ($newQuantity > $product->stock) {
            return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
        }

        $price = $product->discount_price ?? $product->price;

        if ($cartItem) {
            $cartItem->update([
                'quantity' => $newQuantity,
                'gross_amount' => $newQuantity * $price
            ]);
        } else {
            Cart::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'gross_amount' => $request->quantity * $price
            ]);
        }

        return response()->json(['message' => 'Added to cart successfully']);
    }

    public function update(Request $request, $id)
    {
        $cart = Cart::with('product')->findOrFail($id);

        if ($request->quantity > $cart->product->stock) {
            return response()->json(['message' => 'Stock limited!'], 422);
        }

        $price = $cart->product->discount_price ?? $cart->product->price;
        $cart->update([
            'quantity' => $request->quantity,
            'gross_amount' => $request->quantity * $price
        ]);

        return response()->json($cart);
    }

    public function destroy($id)
    {
        Cart::findOrFail($id)->delete();
        return response()->json(['message' => 'Item removed']);
    }
}
