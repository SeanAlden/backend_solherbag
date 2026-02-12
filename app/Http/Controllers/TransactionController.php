<?php

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\Product;
// use App\Models\Transaction;
// use Illuminate\Support\Str;
// use Illuminate\Http\Request;
// use App\Models\TransactionDetail;
// use Illuminate\Support\Facades\DB;
// use App\Http\Controllers\Controller;

// class TransactionController extends Controller
// {
//     public function checkout(Request $request)
//     {
//         $user = $request->user();
//         $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

//         if ($cartItems->isEmpty()) {
//             return response()->json(['message' => 'Cart is empty'], 400);
//         }

//         return DB::transaction(function () use ($user, $cartItems) {
//             $totalAmount = $cartItems->sum('gross_amount');
//             $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

//             $transaction = Transaction::create([
//                 'user_id' => $user->id,
//                 'order_id' => $orderId,
//                 'total_amount' => $totalAmount,
//                 'status' => 'pending'
//             ]);

//             foreach ($cartItems as $item) {
//                 $product = Product::lockForUpdate()->find($item->product_id);

//                 if ($product->stock < $item->quantity) {
//                     throw new \Exception("Stock {$product->name} insufficient");
//                 }

//                 TransactionDetail::create([
//                     'transaction_id' => $transaction->id,
//                     'product_id' => $item->product_id,
//                     'quantity' => $item->quantity,
//                     'price' => $item->product->discount_price ?? $item->product->price
//                 ]);

//                 $product->decrement('stock', $item->quantity);
//             }

//             Cart::where('user_id', $user->id)->delete();

//             return response()->json([
//                 'transaction_id' => $transaction->id,
//                 'order_id' => $orderId
//             ], 201);
//         });
//     }

//     public function index(Request $request)
//     {
//         $transactions = Transaction::with(['details.product'])
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();
//         return response()->json($transactions);
//     }

//     // Melihat semua transaksi (Sisi Admin)
//     public function allTransactions()
//     {
//         $transactions = Transaction::with(['user', 'details.product'])
//             ->latest()
//             ->get();
//         return response()->json($transactions);
//     }

//     // Update Status Transaksi
//     public function updateStatus(Request $request, $id)
//     {
//         $request->validate([
//             'status' => 'required|in:pending,processing,completed,cancelled'
//         ]);

//         $transaction = Transaction::findOrFail($id);
//         $transaction->update(['status' => $request->status]);

//         return response()->json([
//             'message' => "Transaction status updated to {$request->status}",
//             'data' => $transaction
//         ]);
//     }

//     public function show($id)
//     {
//         // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
//         $transaction = Transaction::with(['user', 'details.product'])
//             ->findOrFail($id);

//         return response()->json($transaction);
//     }

//     public function adminShow($id)
//     {
//         // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
//         $transaction = Transaction::with(['user', 'details.product'])
//             ->findOrFail($id);

//         return response()->json($transaction);
//     }

//     public function salesReport(Request $request)
//     {
//         $month = $request->query('month'); // Format: 1-12
//         $year = $request->query('year');   // Format: YYYY
//         $search = $request->query('search'); // Pencarian nama produk
//         $perPage = $request->query('per_page', 10);

//         // Query Builder untuk Agregasi Produk Terjual
//         $query = TransactionDetail::query()
//             ->select(
//                 'products.id',
//                 'products.code',
//                 'products.name',
//                 'products.image',
//                 'categories.name as category_name',
//                 DB::raw('SUM(transaction_details.quantity) as total_sold'),
//                 DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
//             )
//             ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//             ->join('products', 'products.id', '=', 'transaction_details.product_id')
//             ->join('categories', 'categories.id', '=', 'products.category_id')
//             ->where('transactions.status', 'completed'); // Hanya hitung transaksi sukses

//         // Filter Bulan & Tahun
//         if ($month && $year) {
//             $query->whereMonth('transactions.created_at', $month)
//                 ->whereYear('transactions.created_at', $year);
//         } elseif ($year) {
//             $query->whereYear('transactions.created_at', $year);
//         }

//         // Filter Pencarian (Nama Produk atau Kode)
//         if ($search) {
//             $query->where(function ($q) use ($search) {
//                 $q->where('products.name', 'like', "%{$search}%")
//                     ->orWhere('products.code', 'like', "%{$search}%");
//             });
//         }

//         // Grouping & Ordering
//         $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
//             ->orderByDesc('total_revenue') // Urutkan dari omzet tertinggi
//             ->paginate($perPage);

//         return response()->json($report);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Payment;
use Xendit\Configuration;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Xendit\Invoice\InvoiceApi;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Xendit\Invoice\CreateInvoiceRequest;

class TransactionController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    // --- USER ACTIONS ---

    // public function checkout(Request $request)
    // {
    //     $user = $request->user();
    //     $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['message' => 'Cart is empty'], 400);
    //     }

    //     return DB::transaction(function () use ($user, $cartItems) {
    //         $totalAmount = $cartItems->sum('gross_amount');
    //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

    //         // 1. Status awal "awaiting_payment"
    //         $transaction = Transaction::create([
    //             'user_id' => $user->id,
    //             'order_id' => $orderId,
    //             'total_amount' => $totalAmount,
    //             // 'status' => 'awaiting_payment'
    //             'status' => 'awaiting payment'
    //         ]);

    //         $items = [];
    //         foreach ($cartItems as $item) {
    //             $product = Product::lockForUpdate()->find($item->product_id);
    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Stock {$product->name} insufficient");
    //             }

    //             TransactionDetail::create([
    //                 'transaction_id' => $transaction->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $item->product->discount_price ?? $item->product->price
    //             ]);

    //             $product->decrement('stock', $item->quantity);

    //             // Siapkan item untuk Xendit
    //             $items[] = [
    //                 'name' => $item->product->name,
    //                 'quantity' => $item->quantity,
    //                 'price' => (int) ($item->product->discount_price ?? $item->product->price),
    //                 'category' => 'PHYSICAL_PRODUCT'
    //             ];
    //         }

    //         // 2. Generate Xendit Invoice Langsung saat Checkout
    //         $externalId = 'PAY-' . $orderId;
    //         $invoiceRequest = new CreateInvoiceRequest([
    //             'external_id' => $externalId,
    //             'payer_email' => $user->email,
    //             'amount' => (int) $totalAmount,
    //             'description' => 'Payment for Order ' . $orderId,
    //             'items' => $items,
    //             'success_redirect_url' => config('app.frontend_url') . '/orderpage', // Redirect kembali ke order page
    //             'failure_redirect_url' => config('app.frontend_url') . '/orderpage',
    //         ]);

    //         $api = new InvoiceApi();
    //         $invoice = $api->createInvoice($invoiceRequest);

    //         // Simpan Payment dengan URL
    //         Payment::create([
    //             'transaction_id' => $transaction->id,
    //             'external_id' => $externalId,
    //             'checkout_url' => $invoice['invoice_url'],
    //             'amount' => $totalAmount,
    //             'status' => 'PENDING' // Status Xendit
    //         ]);

    //         Cart::where('user_id', $user->id)->delete();

    //         return response()->json([
    //             'transaction_id' => $transaction->id,
    //             'order_id' => $orderId,
    //             'payment_url' => $invoice['invoice_url']
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
        // Eager load 'payment' untuk mendapatkan checkout_url
        $transactions = Transaction::with(['details.product', 'payment'])
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

    public function cancelOrder(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['awaiting_payment', 'pending'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }

        // Logic expire Xendit invoice bisa ditambahkan disini jika perlu

        $transaction->update(['status' => 'cancelled']);
        if ($transaction->payment) {
            $transaction->payment->update(['status' => 'EXPIRED']); // Update status payment lokal
        }

        // Kembalikan stok (Optional logic)
        foreach ($transaction->details as $detail) {
            $detail->product->increment('stock', $detail->quantity);
        }

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }

        $transaction->update(['status' => 'completed']);
        return response()->json(['message' => 'Order completed!']);
    }

    public function requestRefund(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        // Refund bisa diajukan saat processing (sudah bayar) atau completed
        if (!in_array($transaction->status, ['processing', 'completed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        $transaction->update(['status' => 'refund_requested']);
        return response()->json(['message' => 'Refund requested. Waiting for admin approval.']);
    }

    // User klik "Refund Now" setelah disetujui admin
    public function processRefundUser(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'refund_approved') {
            return response()->json(['message' => 'Refund not approved yet.'], 400);
        }

        // --- DISINI MEMANGGIL API REFUND/DISBURSEMENT XENDIT (Simulasi) ---
        // $xendit->createRefund(...)

        // Asumsi refund berhasil
        $transaction->update(['status' => 'refunded']);
        if ($transaction->payment) {
            $transaction->payment->update(['status' => 'REFUNDED']);
        }

        return response()->json(['message' => 'Refund processed successfully. Funds returned.']);
    }

    public function approveRefund($id)
    {
        $transaction = Transaction::findOrFail($id);
        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_approved']);
        return response()->json(['message' => 'Refund request approved.']);
    }

    public function rejectRefund($id)
    {
        $transaction = Transaction::findOrFail($id);
        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_rejected']);
        return response()->json(['message' => 'Refund request rejected.']);
    }

    // Show single transaction
    public function show($id)
    {
        return response()->json(Transaction::with(['user', 'details.product', 'payment'])->findOrFail($id));
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
