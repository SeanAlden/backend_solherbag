<?php

namespace App\Http\Controllers;

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
    // public function checkout(Request $request)
    // {
    //     $user = $request->user();
    //     $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['message' => 'Cart is empty'], 400);
    //     }

    //     return DB::transaction(function () use ($user, $cartItems) {
    //         $totalAmount = $cartItems->sum('gross_amount');
    //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));

    //         // 1. Buat Header Transaksi
    //         $transaction = Transaction::create([
    //             'user_id' => $user->id,
    //             'order_id' => $orderId,
    //             'total_amount' => $totalAmount,
    //             'status' => 'pending'
    //         ]);

    //         foreach ($cartItems as $item) {
    //             // 2. Cek Stok Terakhir (Race Condition Guard)
    //             $product = Product::lockForUpdate()->find($item->product_id);
    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Stock for {$product->name} is insufficient.");
    //             }

    //             // 3. Simpan Detail
    //             TransactionDetail::create([
    //                 'transaction_id' => $transaction->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $item->product->discount_price ?? $item->product->price
    //             ]);

    //             // 4. Potong Stok
    //             $product->decrement('stock', $item->quantity);
    //         }

    //         // 5. Kosongkan Keranjang
    //         Cart::where('user_id', $user->id)->delete();

    //         return response()->json([
    //             'message' => 'Order created successfully',
    //             'order_id' => $orderId
    //         ], 201);
    //     });
    // }

    public function checkout(Request $request)
    {
        $user = $request->user();
        $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        return DB::transaction(function () use ($user, $cartItems) {
            $totalAmount = $cartItems->sum('gross_amount');
            $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
                'status' => 'pending'
            ]);

            foreach ($cartItems as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);

                if ($product->stock < $item->quantity) {
                    throw new \Exception("Stock {$product->name} insufficient");
                }

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->discount_price ?? $item->product->price
                ]);

                $product->decrement('stock', $item->quantity);
            }

            Cart::where('user_id', $user->id)->delete();

            return response()->json([
                'transaction_id' => $transaction->id,
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

    public function show($id)
    {
        // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
        $transaction = Transaction::with(['user', 'details.product'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function adminShow($id)
    {
        // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
        $transaction = Transaction::with(['user', 'details.product'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function salesReport(Request $request)
    {
        $month = $request->query('month'); // Format: 1-12
        $year = $request->query('year');   // Format: YYYY
        $search = $request->query('search'); // Pencarian nama produk
        $perPage = $request->query('per_page', 10);

        // Query Builder untuk Agregasi Produk Terjual
        $query = TransactionDetail::query()
            ->select(
                'products.id',
                'products.code',
                'products.name',
                'products.image',
                'categories.name as category_name',
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('transactions.status', 'completed'); // Hanya hitung transaksi sukses

        // Filter Bulan & Tahun
        if ($month && $year) {
            $query->whereMonth('transactions.created_at', $month)
                ->whereYear('transactions.created_at', $year);
        } elseif ($year) {
            $query->whereYear('transactions.created_at', $year);
        }

        // Filter Pencarian (Nama Produk atau Kode)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.code', 'like', "%{$search}%");
            });
        }

        // Grouping & Ordering
        $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
            ->orderByDesc('total_revenue') // Urutkan dari omzet tertinggi
            ->paginate($perPage);

        return response()->json($report);
    }
}
