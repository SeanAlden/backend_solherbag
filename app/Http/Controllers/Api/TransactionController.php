<?php

namespace App\Http\Controllers\Api;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    public function checkout(Request $request)
    {
        $user = $request->user();
        $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        return DB::transaction(function () use ($user, $cartItems) {
            $totalAmount = $cartItems->sum('gross_amount');
            $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));

            // 1. Buat Header Transaksi
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
                'status' => 'pending'
            ]);

            foreach ($cartItems as $item) {
                // 2. Cek Stok Terakhir (Race Condition Guard)
                $product = Product::lockForUpdate()->find($item->product_id);
                if ($product->stock < $item->quantity) {
                    throw new \Exception("Stock for {$product->name} is insufficient.");
                }

                // 3. Simpan Detail
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->discount_price ?? $item->product->price
                ]);

                // 4. Potong Stok
                $product->decrement('stock', $item->quantity);
            }

            // 5. Kosongkan Keranjang
            Cart::where('user_id', $user->id)->delete();

            return response()->json([
                'message' => 'Order created successfully',
                'order_id' => $orderId
            ], 201);
        });
    }

    public function index(Request $request)
    {
        $transactions = Transaction::with(['details.product'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();
        return response()->json($transactions);
    }

    // Melihat semua transaksi (Sisi Admin)
    public function allTransactions()
    {
        $transactions = Transaction::with(['user', 'details.product'])
            ->latest()
            ->get();
        return response()->json($transactions);
    }

    // Update Status Transaksi
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled'
        ]);

        $transaction = Transaction::findOrFail($id);
        $transaction->update(['status' => $request->status]);

        return response()->json([
            'message' => "Transaction status updated to {$request->status}",
            'data' => $transaction
        ]);
    }
}
