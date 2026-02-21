<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Payment;
use Xendit\Configuration;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Xendit\Refund\RefundApi;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Xendit\Refund\CreateRefund;
use App\Models\TransactionDetail;
use App\Services\BiteshipService;
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
                'status' => 'awaiting_payment'
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
    // public function allTransactions()
    // {
    //     $transactions = Transaction::with(['user', 'details.product'])
    //         ->latest()
    //         ->get();
    //     return response()->json($transactions);
    // }

    public function allTransactions()
    {
        // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
        $transactions = Transaction::with(['user', 'details.product', 'address'])
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

        // [BARU] Logika membatalkan pesanan di server Biteship
        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                $biteshipData = $response->json();

                // Deteksi jika Biteship menolak pembatalan (misalnya kurir sudah dalam perjalanan / "picking_up")
                if (isset($biteshipData['success']) && $biteshipData['success'] === false) {
                    \Illuminate\Support\Facades\Log::warning('Biteship Cancel Error: ' . json_encode($biteshipData));

                    // Anda bisa memblokir pembatalan lokal jika kurir sudah terlanjur jalan
                    return response()->json([
                        'message' => 'Cannot cancel: Courier is already processing this order. (' . ($biteshipData['error'] ?? 'Logistics error') . ')'
                    ], 400);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Biteship Cancel Exception: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to connect to logistics provider.'], 500);
            }
        }

        // Update status database lokal
        $transaction->update(['status' => 'cancelled']);
        if ($transaction->payment) {
            $transaction->payment->update(['status' => 'EXPIRED']); // Update status payment lokal
        }

        // Kembalikan stok
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
        // 1. Validasi Transaksi Lokal
        $transaction = Transaction::with('payment')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($transaction->status !== 'refund_approved') {
            return response()->json(['message' => 'Refund not approved yet.'], 400);
        }

        if (!$transaction->payment) {
            return response()->json(['message' => 'Payment data not found.'], 404);
        }

        // --- PRE-CHECK: Validasi Status Kurir Biteship SEBELUM Refund ---
        $shouldCancelCourier = false;
        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $res = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    // Daftar status di mana pesanan SUDAH TIDAK BISA dibatalkan
                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected'];

                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        // CEGAH REFUND JIKA KURIR SUDAH JALAN/SELESAI
                        return response()->json([
                            'message' => 'Cannot process refund: The package is already in transit or delivered (Status: ' . strtoupper($biteshipStatus) . '). Please return the item first.'
                        ], 400);
                    }

                    // Jika status masih aman (placed, allocated, picking_up), tandai untuk dibatalkan nanti
                    if (!in_array($biteshipStatus, ['cancelled'])) {
                        $shouldCancelCourier = true;
                    }
                }
            } catch (\Exception $e) {
                // Jika API Biteship down, kita harus berhati-hati.
                // Untuk keamanan, batalkan proses refund jika kita tidak bisa memastikan status kurir.
                \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Failed to verify logistics status with Biteship. Please try again later.'
                ], 500);
            }
        }

        // --- EKSEKUSI REFUND KE XENDIT ---
        try {
            // STEP A: Cari Invoice ID
            $invoiceApi = new InvoiceApi();
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception("Invoice not found in Xendit.");
            }

            $xenditInvoiceId = $invoices[0]['id'];

            // STEP B: Coba Refund via API
            $refundApi = new RefundApi();

            $refundRequest = new CreateRefund([
                'invoice_id' => $xenditInvoiceId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => [
                    'order_id' => $transaction->order_id
                ]
            ]);

            $result = $refundApi->createRefund(null, null, $refundRequest);

            // Jika Xendit sukses, update database
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }
            });

            // STEP C: Batalkan Kurir Biteship (Karena kita sudah pastikan statusnya aman untuk dicancel)
            if ($shouldCancelCourier) {
                \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
            }

            return response()->json([
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic'
            ]);
        } catch (XenditSdkException $e) {
            // --- Handling Khusus Jika Channel Tidak Support Refund ---
            $errorMessage = $e->getMessage();

            if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
                // Update status menjadi 'refund_manual_required'
                $transaction->update(['status' => 'refund_manual_required']);

                // Tetap batalkan kurir Biteship karena pesanan ini di-hold untuk refund manual
                if ($shouldCancelCourier) {
                    \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => config('services.biteship.api_key')
                    ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
                }

                return response()->json([
                    'message' => 'Automatic refund not supported for this payment method. Status updated to Manual Check.',
                    'code' => 'MANUAL_REFUND_NEEDED'
                ], 200);
            }

            \Illuminate\Support\Facades\Log::error('Xendit Refund Error: ' . $errorMessage);
            return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('System Refund Error: ' . $e->getMessage());
            return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
        }
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
            ->whereIn('transactions.status', ['completed', 'refund_rejected']);

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

    public function trackOrder($id)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

        // [PERBAIKAN] Validasi menggunakan biteship_order_id
        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function bulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id'
        ]);

        // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
        foreach ($transactions as $transaction) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending']; // Fallback jika belum teralokasi
                }
            } catch (\Exception $e) {
                // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
        return response()->json($trackingData);
    }

    // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
    public function adminBulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id'
        ]);

        // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
        $transactions = Transaction::whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        foreach ($transactions as $transaction) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];
                }
            } catch (\Exception $e) {
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        return response()->json($trackingData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id); // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        if (!$transaction->biteship_order_id) {
            return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
        }

        // Ambil query parameter dari Vue (insurance_shown, dll)
        $queryString = http_build_query($request->all());

        // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
        $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

        try {
            // Tembak URL label Biteship dengan API Key kita
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get($biteshipUrl);

            // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
            }

            return response()->json(['message' => 'Gagal mengambil resi dari Biteship: ' . $response->body()], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
        }
    }

    public function biteshipCallback(Request $request)
    {
        // Biteship mengirimkan token otentikasi di Header untuk keamanan
        $biteshipSignature = $request->header('biteship-signature');
        // Validasi signature jika perlu (Opsional tapi disarankan di Production)

        $event = $request->input('event'); // Contoh: 'order.status.updated' atau 'waybill.ready'
        $biteshipOrderId = $request->input('order_id');
        $status = $request->input('status'); // picking_up, dropped, delivered, dll
        $waybill = $request->input('courier_tracking_id'); // Ini adalah resi

        \Log::info('Biteship Webhook Received: ', $request->all());

        $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 200);
        }

        // 1. Jika ada update Nomor Resi yang menyusul
        if ($waybill && $transaction->tracking_number === 'Pending') {
            $transaction->update(['tracking_number' => $waybill]);
        }

        // 2. Jika Anda ingin Auto-Complete transaksi saat kurir mengubah status jadi 'delivered'
        // (Ini opsional, karena Anda sudah punya tombol "Order Received" untuk ditekan user)
        if ($status === 'delivered' && $transaction->status === 'processing') {
            $transaction->update(['status' => 'completed']);
        }

        return response()->json(['message' => 'Webhook processed']);
    }
}
