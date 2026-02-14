<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Payment;
use Xendit\Configuration;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Xendit\Invoice\InvoiceApi;
use App\Services\BiteshipService;
use Xendit\Invoice\CreateInvoiceRequest;

class PaymentController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    // public function createInvoice(Request $request)
    // {
    //     $request->validate([
    //         'transaction_id' => 'required|exists:transactions,id',
    //         'address_id' => 'required',
    //         // Validasi data kurir dari frontend
    //         'courier_company' => 'required|string',
    //         'courier_type' => 'required|string',
    //         'shipping_cost' => 'required|numeric'
    //     ]);

    //     $transaction = Transaction::with(['user', 'details.product', 'payment'])
    //         ->findOrFail($request->transaction_id);

    //     // --- UPDATE TRANSAKSI DENGAN ONGKIR & ALAMAT ---
    //     // Pastikan kita tidak menambah ongkir berkali-kali jika invoice di-regenerate
    //     if (!$transaction->shipping_cost || $transaction->shipping_cost == 0) {
    //         $transaction->update([
    //             'address_id' => $request->address_id,
    //             'courier_company' => $request->courier_company,
    //             'courier_type' => $request->courier_type,
    //             'shipping_cost' => $request->shipping_cost,
    //             'total_amount' => $transaction->total_amount + $request->shipping_cost  // Total Baru!
    //         ]);
    //     }

    //     // $externalId = 'PAY-' . $transaction->order_id;
    //     $externalId = 'PAY-' . $transaction->order_id . ($transaction->payment ? '-' . time() : '');

    //     // Optional: itemized invoice (recommended)
    //     $items = [];
    //     foreach ($transaction->details as $detail) {
    //         $items[] = [
    //             'name' => $detail->product->name,
    //             'quantity' => $detail->quantity,
    //             'price' => (int) $detail->price,
    //             'category' => 'PHYSICAL_PRODUCT'
    //         ];
    //     }

    //     $invoiceRequest = new CreateInvoiceRequest([
    //         'external_id' => $externalId,
    //         'payer_email' => $transaction->user->email,
    //         'amount' => (int) $transaction->total_amount,
    //         'description' => 'Payment for Order ' . $transaction->order_id,
    //         'items' => $items,
    //         // 'success_redirect_url' => config('frontend.url') . '/payment-success',
    //         'success_redirect_url' => config('app.frontend_url')
    //             . '/payment-success?external_id=' . $externalId
    //             . '&order_id=' . $transaction->order_id,
    //         'failure_redirect_url' => config('frontend.url') . '/payment-failed',
    //     ]);

    //     $api = new InvoiceApi();
    //     $invoice = $api->createInvoice($invoiceRequest);

    //     Payment::create([
    //         'transaction_id' => $transaction->id,
    //         'external_id' => $externalId,
    //         'checkout_url' => $invoice['invoice_url'],
    //         'amount' => $transaction->total_amount,
    //         'status' => 'pending'
    //     ]);

    //     return response()->json([
    //         'checkout_url' => $invoice['invoice_url']
    //     ]);
    // }

    public function createInvoice(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship', // Validasi method
            // Jadikan nullable karena jika 'free', data ini mungkin kosong
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric'
        ]);

        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->findOrFail($request->transaction_id);

        if (!$transaction->shipping_cost || $transaction->shipping_cost == 0) {

            // --- LOGIKA PENENTUAN BIAYA & KURIR ---
            $shippingCost = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;
            $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
            $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

            $transaction->update([
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method, // Simpan method
                'courier_company' => $courierCompany,
                'courier_type' => $courierType,
                'shipping_cost' => $shippingCost,
                'total_amount' => $transaction->total_amount + $shippingCost
            ]);
        }

        $externalId = 'PAY-' . $transaction->order_id . ($transaction->payment ? '-' . time() : '');

        $items = [];
        foreach ($transaction->details as $detail) {
            $items[] = [
                'name' => $detail->product->name,
                'quantity' => $detail->quantity,
                'price' => (int) $detail->price,
                'category' => 'PHYSICAL_PRODUCT'
            ];
        }

        // Tambahkan ongkir ke item Xendit jika ada biaya agar hitungan balance (tidak perlu, tapi opsional untuk kerapihan invoice)
        if ($transaction->shipping_cost > 0) {
            $items[] = [
                'name' => 'Shipping Cost (' . $transaction->courier_company . ')',
                'quantity' => 1,
                'price' => (int) $transaction->shipping_cost,
                'category' => 'SHIPPING_FEE'
            ];
        }

        $invoiceRequest = new CreateInvoiceRequest([
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            'amount' => (int) $transaction->total_amount, // Menggunakan total_amount yang sudah + ongkir
            'description' => 'Payment for Order ' . $transaction->order_id,
            'items' => $items,
            'success_redirect_url' => config('app.frontend_url')
                . '/payment-success?external_id=' . $externalId
                . '&order_id=' . $transaction->order_id,
            'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
        ]);

        $api = new InvoiceApi();
        $invoice = $api->createInvoice($invoiceRequest);

        Payment::create([
            'transaction_id' => $transaction->id,
            'external_id' => $externalId,
            'checkout_url' => $invoice['invoice_url'],
            'amount' => $transaction->total_amount,
            'status' => 'pending'
        ]);

        return response()->json([
            'checkout_url' => $invoice['invoice_url']
        ]);
    }

    // Callback ini menangani perubahan status dari Xendit
    // public function callback(Request $request)
    // {
    //     $getToken = $request->header('x-callback-token');
    //     $callbackToken = env("XENDIT_CALLBACK_TOKEN");

    //     if (!$callbackToken || $getToken != $callbackToken) {
    //         return response()->json(['message' => 'Unauthorized'], 401);
    //     }

    //     $payment = Payment::where('external_id', $request->external_id)->first();

    //     if (!$payment) {
    //         return response()->json(['message' => 'Payment not found'], 404);
    //     }

    //     $payment->update(['status' => $request->status]);

    //     if ($request->status === 'PAID') {
    //         $payment->transaction->update(['status' => 'completed']);
    //     }

    //     return response()->json(['message' => 'OK']);
    // }

    // Callback ini menangani perubahan status dari Xendit
    // public function callback(Request $request)
    // {
    //     // Validasi Token Xendit disini (wajib di production)

    //     $payment = Payment::where('external_id', $request->external_id)->first();
    //     if (!$payment)
    //         return response()->json(['message' => 'Payment not found'], 404);

    //     $status = $request->status;  // PENDING, PAID, EXPIRED, FAILED
    //     $payment->update(['status' => $status]);

    //     $transaction = $payment->transaction;

    //     if ($status === 'PAID') {
    //         // Nomor 4: Update ke processing saat berhasil bayar
    //         $transaction->update(['status' => 'processing']);
    //         // --- TRIGGER BITESHIP CREATE ORDER ---
    //         try {
    //             $biteship = new BiteshipService();
    //             $order = $biteship->createOrder($transaction);

    //             // Simpan nomor resi (AWB) dan Order ID Biteship ke database
    //             if (isset($order['id'])) {
    //                 $transaction->update([
    //                     'biteship_order_id' => $order['id'],
    //                     'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending'
    //                 ]);
    //             }
    //         } catch (\Exception $e) {
    //             \Log::error('Biteship Error: ' . $e->getMessage());
    //             // Transaksi tetap sukses (processing), admin bisa mengurus resi manual jika API gagal
    //         }
    //     } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
    //         // Jika invoice expired di Xendit, cancel transaksi
    //         if ($transaction->status !== 'cancelled') {
    //             $transaction->update(['status' => 'cancelled']);
    //             // Kembalikan stok logic here
    //         }
    //     }
    //     // Note: Xendit kadang mengirim status 'PENDING' lagi jika user memilih metode pembayaran tapi belum bayar.
    //     // Kita bisa update transaction status ke 'pending' jika awalnya 'awaiting_payment'
    //     // elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
    //     elseif ($status === 'PENDING' && $transaction->status === 'awaiting payment') {
    //         $transaction->update(['status' => 'pending']);
    //     }

    //     return response()->json(['message' => 'Callback received']);
    // }

    public function callback(Request $request)
    {
        $payment = Payment::where('external_id', $request->external_id)->first();
        if (!$payment) return response()->json(['message' => 'Payment not found'], 404);

        $status = $request->status;
        $payment->update(['status' => $status]);
        $transaction = $payment->transaction;

        if ($status === 'PAID') {
            $transaction->update(['status' => 'processing']);

            // --- PENCEGAHAN BITESHIP JIKA FREE SHIPPING ---
            if ($transaction->shipping_method === 'biteship') {
                try {
                    $biteship = new BiteshipService();
                    $order = $biteship->createOrder($transaction);

                    if (isset($order['id'])) {
                        $transaction->update([
                            'biteship_order_id' => $order['id'],
                            'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending'
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Biteship Error: ' . $e->getMessage());
                }
            } else {
                // Jika shipping_method == 'free', kurir internal yang mengurus.
                // Bisa update tracking number dengan resi internal jika perlu.
                $transaction->update(['tracking_number' => 'Internal-Delivery']);
            }
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            if ($transaction->status !== 'cancelled') {
                $transaction->update(['status' => 'cancelled']);
            }
        } elseif ($status === 'PENDING' && $transaction->status === 'awaiting payment') {
            $transaction->update(['status' => 'pending']);
        }

        return response()->json(['message' => 'Callback received']);
    }

    // public function getShippingRates(Request $request)
    // {
    //     $request->validate(['address_id' => 'required|exists:addresses,id']);

    //     $address = Address::find($request->address_id);
    //     $postalCode = $address->details['postal_code'];  // Sesuaikan dengan struktur JSON di database Anda

    //     $biteship = new BiteshipService();
    //     $rates = $biteship->getRates($postalCode);

    //     return response()->json($rates);
    // }

    // public function getShippingRates(Request $request)
    // {
    //     $request->validate(['address_id' => 'required|exists:addresses,id']);

    //     $address = Address::find($request->address_id);

    //     // Langsung panggil properti $address->postal_code
    //     if (!$address || !$address->postal_code) {
    //         return response()->json([
    //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.'
    //         ], 400);
    //     }

    //     try {
    //         $biteship = new BiteshipService();
    //         // Langsung passing $address->postal_code
    //         $rates = $biteship->getRates($address->postal_code);

    //         return response()->json($rates);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'message' => 'Gagal mengambil ongkos kirim: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function getShippingRates(Request $request)
    {
        $request->validate(['address_id' => 'required|exists:addresses,id']);

        $address = Address::find($request->address_id);

        if (!$address || !$address->postal_code) {
            return response()->json([
                'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.'
            ], 400);
        }

        try {
            $biteship = new BiteshipService();
            $rates = $biteship->getRates($address->postal_code);

            // [PERBAIKAN] Cek jika API Biteship memberikan respon success: false
            if (isset($rates['success']) && $rates['success'] === false) {
                return response()->json([
                    'message' => 'Biteship API Error: ' . ($rates['error'] ?? 'Unknown error')
                ], 400); // Return 400 agar masuk ke block catch() di frontend
            }

            return response()->json($rates);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil ongkos kirim: ' . $e->getMessage()
            ], 500);
        }
    }
}
