<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TransferReceivePayment;

class TransferReceivePaymentController extends Controller
{
    public function index()
    {
        // Ambil data beserta relasi COA-nya
        $payments = TransferReceivePayment::with(['kreditCoa', 'debitCoa'])
            ->orderBy('date', 'desc')
            ->latest()
            ->get();
            
        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'kredit_coa_id' => 'required|exists:coas,id',
            'debit_coa_id' => 'required|exists:coas,id',
            'recipient_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:1',
            'date' => 'required|date',
            'type' => 'required|in:transfer,receive',
            'description' => 'nullable|string|max:255',
        ]);

        $data = $request->all();
        
        // Generate No Transaction Otomatis (misal: PAY-20260227-XXXX)
        $prefix = $data['type'] === 'receive' ? 'RCV' : 'TRF';
        $data['no_transaction'] = $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));

        $payment = TransferReceivePayment::create($data);

        return response()->json($payment->load(['kreditCoa', 'debitCoa']), 201);
    }

    public function update(Request $request, $id)
    {
        $payment = TransferReceivePayment::findOrFail($id);

        $request->validate([
            'kredit_coa_id' => 'required|exists:coas,id',
            'debit_coa_id' => 'required|exists:coas,id',
            'recipient_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:1',
            'date' => 'required|date',
            'type' => 'required|in:transfer,receive',
            'description' => 'nullable|string|max:255',
        ]);

        $data = $request->all();
        
        // Update prefix no_transaction jika tipe berubah
        $prefix = $data['type'] === 'receive' ? 'RCV' : 'TRF';
        if (!str_starts_with($payment->no_transaction, $prefix)) {
            $data['no_transaction'] = $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));
        }

        $payment->update($data);

        return response()->json($payment->load(['kreditCoa', 'debitCoa']));
    }

    public function destroy($id)
    {
        $payment = TransferReceivePayment::findOrFail($id);
        $payment->delete();

        return response()->json(['message' => 'Payment record deleted successfully']);
    }
}