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

    public function createInvoice(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric', // Ini adalah Harga Dasar (Base Rate) dari Frontend
            'delivery_type' => 'nullable|string|in:now,later,scheduled',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i'
        ]);

        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->findOrFail($request->transaction_id);

        // [PERBAIKAN LOGIKA] Hitung Total Quantity Barang
        $totalQuantity = $transaction->details->sum('quantity') ?: 1;

        if (!$transaction->shipping_cost || $transaction->shipping_cost == 0) {

            // Harga dasar pengiriman per item (atau per kg)
            $baseShippingRate = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;

            // [PERBAIKAN LOGIKA] Total Shipping = Harga Dasar x Total Item
            $totalShippingCost = $baseShippingRate * $totalQuantity;

            $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
            $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

            $transaction->update([
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method,
                'courier_company' => $courierCompany,
                'courier_type' => $courierType,
                'shipping_cost' => $totalShippingCost, // Simpan Total Ongkir
                'total_amount' => $transaction->total_amount, // Tambahkan Total Ongkir ke Total Harga
                'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                'delivery_date' => $request->delivery_date,
                'delivery_time' => $request->delivery_time,
                'status' => 'pending'
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

        // Penambahan Ongkir ke Xendit Invoice
        $basePriceXendit = 0;

        if ($transaction->shipping_cost > 0) {
            // Xendit butuh harga satuan (Base Price), jadi kita bagi kembali dari total_shipping_cost yang tersimpan
            $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
            // $basePriceXendit = $transaction->shipping_cost;

            $items[] = [
                'name' => 'Shipping Cost (' . $transaction->courier_company . ')',
                'quantity' => (int) $totalQuantity,
                'price' => (int) $basePriceXendit,
                'category' => 'SHIPPING_FEE'
            ];
        }

        $invoiceRequest = new CreateInvoiceRequest([
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            'amount' => (int) $transaction->total_amount + $basePriceXendit * $totalQuantity, // Sekarang nilainya sudah tepat secara matematika!
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
    public function callback(Request $request)
    {
        $payment = Payment::where('external_id', $request->external_id)->first();
        if (!$payment) return response()->json(['message' => 'Payment not found'], 404);

        $status = $request->status;
        $payment->update(['status' => $status]);
        $transaction = $payment->transaction;

        if ($status === 'PAID') {

            $paymentMethod = $request->input('payment_method', 'Unknown');
            $paymentChannel = $request->input('payment_channel', '');
            $fullPaymentMethod = trim($paymentMethod . ' ' . $paymentChannel);

            // Jika shipping_method adalah 'free' (Ambil di Toko), langsung set ke 'completed'.
            // Jika menggunakan Biteship, set ke 'processing' seperti biasa.
            $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

            // Update status dan payment method sekaligus
            $transaction->update([
                'status' => $targetTransactionStatus,
                'payment_method' => $fullPaymentMethod
            ]);

            // --- EKSEKUSI PEMESANAN KURIR ---
            if ($transaction->shipping_method === 'biteship') {
                try {
                    $biteship = new BiteshipService();
                    $order = $biteship->createOrder($transaction);

                    if (isset($order['success']) && $order['success'] === false) {
                        \Log::error('Gagal Create Order Biteship: ' . json_encode($order));
                    }

                    if (isset($order['id'])) {
                        $transaction->update([
                            'biteship_order_id' => $order['id'],
                            'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending'
                        ]);
                    } else {
                        $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');
                        $transaction->update([
                            'tracking_number' => 'API ERR: ' . substr($errorMsg, 0, 200)
                        ]);
                        \Log::error('Biteship Create Order Failed: ' . json_encode($order));
                    }
                } catch (\Exception $e) {
                    $transaction->update([
                        'tracking_number' => 'SYS ERR: ' . substr($e->getMessage(), 0, 200)
                    ]);
                    \Log::error('Biteship Exception: ' . $e->getMessage());
                }
            } else {
                // Untuk transaksi 'free' shipping, beri label Internal-Pickup
                $transaction->update(['tracking_number' => 'In-Store Pickup']);
            }
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            if ($transaction->status !== 'cancelled') {
                $transaction->update(['status' => 'cancelled']);
            }
        } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
            $transaction->update(['status' => 'pending']);
        }

        return response()->json(['message' => 'Callback processed']);
    }

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

            // [PERBAIKAN] Kirim seluruh objek $address, bukan cuma kode pos
            // Sebelumnya: $rates = $biteship->getRates($address->postal_code);
            $rates = $biteship->getRates($address);

            // Cek jika API Biteship memberikan respon success: false
            if (isset($rates['success']) && $rates['success'] === false) {
                return response()->json([
                    'message' => 'Biteship API Error: ' . ($rates['error'] ?? 'Unknown error')
                ], 400);
            }

            return response()->json($rates);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil ongkos kirim: ' . $e->getMessage()
            ], 500);
        }
    }
}
