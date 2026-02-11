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

    public function callback(Request $request)
    {
        $payment = Payment::where('external_id', $request->external_id)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $payment->update(['status' => $request->status]);

        if ($request->status === 'PAID') {
            $payment->transaction->update(['status' => 'completed']);
        }

        return response()->json(['message' => 'OK']);
    }
}
