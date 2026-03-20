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
            'delivery_time' => 'nullable|date_format:H:i',
            'use_points' => 'nullable|integer|min:0',
        ]);

        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->findOrFail($request->transaction_id);

        if ($transaction->payment && $transaction->payment->status === 'pending' && ! empty($transaction->payment->checkout_url)) {
            return response()->json([
                'checkout_url' => $transaction->payment->checkout_url,
            ]);
        }

        // [PERBAIKAN LOGIKA] Hitung Total Quantity Barang
        $totalQuantity = $transaction->details->sum('quantity') ?: 1;

        if (! $transaction->shipping_cost || $transaction->shipping_cost == 0) {

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
                'status' => 'pending',
            ]);
        }

        $user = $request->user();
        $pointsUsed = 0;
        $pointDiscountAmount = 0;
        $conversionRate = 1000; // 1 Poin = Rp 1.000 Diskon

        if ($request->use_points > 0 && $user->is_membership) {
            // Pastikan user tidak menggunakan poin lebih dari yang mereka miliki
            $pointsUsed = min($request->use_points, $user->point);
            $pointDiscountAmount = $pointsUsed * $conversionRate;

            // Pastikan diskon poin tidak melebihi harga produk (Subtotal)
            // Biasanya ongkir tidak boleh dipotong pakai poin, hanya harga barang
            $pointDiscountAmount = min($pointDiscountAmount, $transaction->total_amount);

            // Jika poin jadi dipakai, potong dari saldo user SEKARANG
            if ($pointsUsed > 0) {
                $user->decrement('point', $pointsUsed);
            }
        }

        $externalId = 'PAY-'.$transaction->order_id.($transaction->payment ? '-'.time() : '');

        $items = [];
        foreach ($transaction->details as $detail) {
            $items[] = [
                'name' => $detail->product->name,
                'quantity' => $detail->quantity,
                'price' => (int) $detail->price,
                'category' => 'PHYSICAL_PRODUCT',
            ];
        }

        // Tambahkan item "Diskon Poin" ke Invoice Xendit sebagai nilai minus
        if ($pointDiscountAmount > 0) {
            $items[] = [
                'name' => 'Loyalty Point Discount ('.$pointsUsed.' Pts)',
                'quantity' => 1,
                'price' => -(int) $pointDiscountAmount, // Nilai minus agar memotong total tagihan Xendit
                'category' => 'DISCOUNT',
            ];
        }

        // Penambahan Ongkir ke Xendit Invoice
        $basePriceXendit = 0;
        if ($transaction->shipping_cost > 0) {
            // Xendit butuh harga satuan (Base Price), jadi kita bagi kembali dari total_shipping_cost yang tersimpan
            $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
            // $basePriceXendit = $transaction->shipping_cost;
            $items[] = [
                'name' => 'Shipping Cost ('.$transaction->courier_company.')',
                'quantity' => (int) $totalQuantity,
                'price' => (int) $basePriceXendit,
                'category' => 'SHIPPING_FEE',
            ];
        }

        // Hitung Total Pembayaran Akhir
        $finalAmount = (int) $transaction->total_amount + ($basePriceXendit * $totalQuantity) - $pointDiscountAmount;

        $invoiceRequest = new CreateInvoiceRequest([
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            // 'amount' => (int) $transaction->total_amount + $basePriceXendit * $totalQuantity, // Sekarang nilainya sudah tepat secara matematika!
            'amount' => $finalAmount,
            'description' => 'Payment for Order '.$transaction->order_id,
            'items' => $items,
            'success_redirect_url' => config('app.frontend_url')
                .'/payment-success?external_id='.$externalId
                .'&order_id='.$transaction->order_id,
            'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
        ]);

        $api = new InvoiceApi;
        $invoice = $api->createInvoice($invoiceRequest);

        Payment::create([
            'transaction_id' => $transaction->id,
            'external_id' => $externalId,
            'checkout_url' => $invoice['invoice_url'],
            'amount' => $transaction->total_amount,
            'status' => 'pending',
        ]);

        return response()->json([
            'checkout_url' => $invoice['invoice_url'],
        ]);
    }

    // Callback ini menangani perubahan status dari Xendit
    public function callback(Request $request)
    {
        $payment = Payment::where('external_id', $request->external_id)->first();
        if (! $payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $status = $request->status;
        $payment->update(['status' => $status]);
        $transaction = $payment->transaction;

        if ($status === 'PAID') {

            $paymentMethod = $request->input('payment_method', 'Unknown');
            $paymentChannel = $request->input('payment_channel', '');
            $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

            // Jika shipping_method adalah 'free' (Ambil di Toko), langsung set ke 'completed'.
            // Jika menggunakan Biteship, set ke 'processing' seperti biasa.
            $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

            // Update status dan payment method sekaligus
            $transaction->update([
                'status' => $targetTransactionStatus,
                'payment_method' => $fullPaymentMethod,
            ]);

            // Jika Free Shipping (langsung completed), tambahkan poin ke user
            // if ($targetTransactionStatus === 'completed' && $transaction->point > 0 && $transaction->user->is_membership) {
            //     $transaction->user->increment('point', $transaction->point);
            // }

            // Jika Free Shipping (langsung completed), tambahkan poin ke user
            if ($targetTransactionStatus === 'completed') {
                // [PERBAIKAN] Cek apakah dia layak jadi member
                $this->checkAndAssignMembership($transaction->user);

                // Refresh data user setelah pengecekan
                $transaction->user->refresh();

                if ($transaction->point > 0 && $transaction->user->is_membership) {
                    $transaction->user->increment('point', $transaction->point);
                }
            }

            // --- EKSEKUSI PEMESANAN KURIR ---
            // if ($transaction->shipping_method === 'biteship') {
            //     try {
            //         $biteship = new BiteshipService();
            //         $order = $biteship->createOrder($transaction);

            //         if (isset($order['success']) && $order['success'] === false) {
            //             \Log::error('Gagal Create Order Biteship: ' . json_encode($order));
            //         }

            //         if (isset($order['id'])) {
            //             $transaction->update([
            //                 'biteship_order_id' => $order['id'],
            //                 'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending'
            //             ]);
            //         } else {
            //             $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');
            //             $transaction->update([
            //                 'tracking_number' => 'API ERR: ' . substr($errorMsg, 0, 200)
            //             ]);
            //             \Log::error('Biteship Create Order Failed: ' . json_encode($order));
            //         }
            //     } catch (\Exception $e) {
            //         $transaction->update([
            //             'tracking_number' => 'SYS ERR: ' . substr($e->getMessage(), 0, 200)
            //         ]);
            //         \Log::error('Biteship Exception: ' . $e->getMessage());
            //     }
            // } else {
            //     // Untuk transaksi 'free' shipping, beri label Internal-Pickup
            //     $transaction->update(['tracking_number' => 'In-Store Pickup']);
            // }

            // --- EKSEKUSI PEMESANAN KURIR ---
            if ($transaction->shipping_method === 'biteship') {
                try {
                    $biteship = new BiteshipService;
                    $order = $biteship->createOrder($transaction);

                    if (isset($order['success']) && $order['success'] === false) {
                        \Log::error('Gagal Create Order Biteship: '.json_encode($order));
                    }

                    if (isset($order['id'])) {
                        $transaction->update([
                            'biteship_order_id' => $order['id'],
                            'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
                            'shipping_status' => strtolower($order['status'] ?? 'pending'), // [PERBAIKAN] Simpan status awal
                        ]);
                    } else {
                        $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');
                        $transaction->update([
                            'tracking_number' => 'API ERR: '.substr($errorMsg, 0, 200),
                            'shipping_status' => 'error', // [PERBAIKAN]
                        ]);
                        \Log::error('Biteship Create Order Failed: '.json_encode($order));
                    }
                } catch (\Exception $e) {
                    $transaction->update([
                        'tracking_number' => 'SYS ERR: '.substr($e->getMessage(), 0, 200),
                        'shipping_status' => 'error', // [PERBAIKAN]
                    ]);
                    \Log::error('Biteship Exception: '.$e->getMessage());
                }
            } else {
                // Untuk transaksi 'free' shipping, beri label Internal-Pickup
                $transaction->update([
                    'tracking_number' => 'In-Store Pickup',
                    'shipping_status' => 'ready_for_pickup', // [PERBAIKAN]
                ]);
            }
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            if ($transaction->status !== 'cancelled') {
                $transaction->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled', // [PERBAIKAN] Sinkronisasi status pengiriman
                ]);

                // [PERBAIKAN] KEMBALIKAN POIN JIKA EXPIRED
                if ($transaction->points_used > 0) {
                    $transaction->user->increment('point', $transaction->points_used);
                }
            }
        } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
            $transaction->update(['status' => 'pending']);
        }

        return response()->json(['message' => 'Callback processed']);
    }

    // public function getShippingRates(Request $request)
    // {
    //     $request->validate(['address_id' => 'required|exists:addresses,id']);

    //     $address = Address::find($request->address_id);

    //     if (! $address || ! $address->postal_code) {
    //         return response()->json([
    //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
    //         ], 400);
    //     }

    //     try {
    //         $biteship = new BiteshipService;

    //         // [PERBAIKAN] Kirim seluruh objek $address, bukan cuma kode pos
    //         // Sebelumnya: $rates = $biteship->getRates($address->postal_code);
    //         $rates = $biteship->getRates($address);

    //         // Cek jika API Biteship memberikan respon success: false
    //         if (isset($rates['success']) && $rates['success'] === false) {
    //             return response()->json([
    //                 'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
    //             ], 400);
    //         }

    //         return response()->json($rates);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function getShippingRates(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            // [PERBAIKAN 1] Tangkap total barang dari keranjang
            'total_quantity' => 'required|integer|min:1'
        ]);

        $address = Address::find($request->address_id);

        if (! $address || ! $address->postal_code) {
            return response()->json([
                'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
            ], 400);
        }

        try {
            $biteship = new BiteshipService;

            // [PERBAIKAN 2] Hitung berat riil (Asumsi 1 Tas = 1000 gram / 1 KG)
            $weight = $request->total_quantity * 1000;

            // Kirim berat riil ke Biteship
            $rates = $biteship->getRates($address, $weight);

            if (isset($rates['success']) && $rates['success'] === false) {
                return response()->json([
                    'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
                ], 400);
            }

            return response()->json($rates);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
            ], 500);
        }
    }

    // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
    private function checkAndAssignMembership($user)
    {
        // Jika user sudah member, tidak perlu cek lagi
        if ($user->is_membership) {
            return;
        }

        // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

        // Jika total belanja >= 100.000, jadikan member
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }
}
