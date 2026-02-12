<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Xendit\Configuration;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Xendit\Invoice\InvoiceApi;
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
            'address_id' => 'required'
        ]);

        $transaction = Transaction::with('user', 'details.product')
            ->findOrFail($request->transaction_id);

        $externalId = 'PAY-' . $transaction->order_id;

        // Optional: itemized invoice (recommended)
        $items = [];
        foreach ($transaction->details as $detail) {
            $items[] = [
                'name' => $detail->product->name,
                'quantity' => $detail->quantity,
                'price' => (int) $detail->price,
                'category' => 'PHYSICAL_PRODUCT'
            ];
        }

        $invoiceRequest = new CreateInvoiceRequest([
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            'amount' => (int) $transaction->total_amount,
            'description' => 'Payment for Order ' . $transaction->order_id,
            'items' => $items,
            // 'success_redirect_url' => config('frontend.url') . '/payment-success',
            'success_redirect_url' => config('app.frontend_url')
                . '/payment-success?external_id=' . $externalId
                . '&order_id=' . $transaction->order_id,
            'failure_redirect_url' => config('frontend.url') . '/payment-failed',
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
    public function callback(Request $request)
    {
        // Validasi Token Xendit disini (wajib di production)

        $payment = Payment::where('external_id', $request->external_id)->first();
        if (!$payment) return response()->json(['message' => 'Payment not found'], 404);

        $status = $request->status; // PENDING, PAID, EXPIRED, FAILED
        $payment->update(['status' => $status]);

        $transaction = $payment->transaction;

        if ($status === 'PAID') {
            // Nomor 4: Update ke processing saat berhasil bayar
            $transaction->update(['status' => 'processing']);
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            // Jika invoice expired di Xendit, cancel transaksi
            if ($transaction->status !== 'cancelled') {
                $transaction->update(['status' => 'cancelled']);
                // Kembalikan stok logic here
            }
        }
        // Note: Xendit kadang mengirim status 'PENDING' lagi jika user memilih metode pembayaran tapi belum bayar.
        // Kita bisa update transaction status ke 'pending' jika awalnya 'awaiting_payment'
        // elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
        elseif ($status === 'PENDING' && $transaction->status === 'awaiting payment') {
            $transaction->update(['status' => 'pending']);
        }

        return response()->json(['message' => 'Callback received']);
    }
}
