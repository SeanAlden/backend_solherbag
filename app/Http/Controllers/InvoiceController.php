<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use Illuminate\Http\Request;
use App\Models\SupplierData;
use App\Models\PaymentHistory;
use App\Models\InvoiceSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    // ==========================================
    // BAGIAN 1: MANAJEMEN SUPPLIER
    // ==========================================

    public function indexSupplier()
    {
        $suppliers = SupplierData::with('invoices')->latest()->get();
        $invoices = InvoiceSupplier::all();

        $stats = [
            'total_supplier' => $suppliers->count(),
            'total_invoice' => $invoices->count(),
            'total_nominal' => $invoices->sum('amount'),
            'unpaid_invoice' => $invoices->where('payment_status', 'Not Yet')->count(),
        ];

        return response()->json([
            'suppliers' => $suppliers,
            'stats' => $stats
        ]);
    }

    public function storeSupplier(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'no_telp' => 'required',
            'email' => 'required|email',
        ]);

        $supplier = SupplierData::create($request->all());
        return response()->json($supplier, 201);
    }

    public function updateSupplier(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'no_telp' => 'required',
            'email' => 'required|email',
        ]);

        $supplier = SupplierData::findOrFail($id);
        $supplier->update($request->all());

        return response()->json($supplier);
    }

    public function deleteSupplier($id)
    {
        try {
            $supplier = SupplierData::findOrFail($id);
            // Validasi jika punya invoice
            if ($supplier->invoices()->exists()) {
                return response()->json(['message' => 'Gagal menghapus. Supplier ini memiliki data Invoice.'], 400);
            }
            $supplier->delete();
            return response()->json(['message' => 'Supplier deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete supplier.'], 500);
        }
    }


    // ==========================================
    // BAGIAN 2: MANAJEMEN INVOICE
    // ==========================================

    public function indexInvoice(Request $request)
    {
        $query = InvoiceSupplier::with(['supplier', 'debitCoa', 'kreditCoa']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('no_invoice', 'like', "%{$search}%")
                ->orWhereHas('supplier', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
        }
        if ($request->filled('payment_status') && $request->payment_status !== 'All') {
            $query->where('payment_status', $request->payment_status);
        }

        $invoices = $query->orderBy('updated_at', 'desc')->get();
        return response()->json($invoices);
    }

    public function storeInvoice(Request $request)
    {
        $request->validate([
            'no_invoice' => 'required|unique:invoice_suppliers',
            'supplier_id' => 'required|exists:supplier_data,id',
            'amount' => 'required|numeric',
            'kredit_coa_id' => 'required|exists:coas,id',
            'debit_coa_id' => 'required|exists:coas,id',
            'image_invoice' => 'required|string',
        ]);

        $data = $request->all();

        if (empty($data['date']))
            $data['date'] = now();

        if (empty($data['deadline_invoice']))
            $data['deadline_invoice'] = now()->addDays(30);

        $invoice = InvoiceSupplier::create($data);

        return response()->json($invoice, 201);
    }

    public function updateInvoice(Request $request, $id)
    {
        $invoice = InvoiceSupplier::findOrFail($id);

        $request->validate([
            'no_invoice' => "required|unique:invoice_suppliers,no_invoice,$id",
            'supplier_id' => 'required|exists:supplier_data,id',
            'amount' => 'required|numeric',
            'image_invoice' => 'nullable|string'
        ]);

        $data = $request->all();

        /*
        DELETE OLD FILE IF CHANGED
        */
        if (
            $request->image_invoice &&
            $invoice->image_invoice !== $request->image_invoice
        ) {

            $oldPath = str_replace(
                Storage::disk('s3')->url(''),
                '',
                $invoice->image_invoice
            );

            Storage::disk('s3')->delete($oldPath);
        }

        $invoice->update($data);

        return response()->json($invoice);
    }

    public function processPayment(Request $request, $id)
    {
        $invoice = InvoiceSupplier::findOrFail($id);

        $request->validate([
            'payment_date' => 'required|date',
            'payment_method' => 'required',
            'amount' => 'required|numeric|min:1',
            'image_proof' => 'required|string'
        ]);

        DB::beginTransaction();

        try {

            if (
                $invoice->image_proof &&
                $invoice->image_proof !== $request->image_proof
            ) {

                $oldPath = str_replace(
                    Storage::disk('s3')->url(''),
                    '',
                    $invoice->image_proof
                );

                Storage::disk('s3')->delete($oldPath);
            }

            $invoice->update([
                'payment_status' => 'Paid',
                'payment_method' => $request->payment_method,
                'image_proof' => $request->image_proof
            ]);

            PaymentHistory::create([
                'invoice_id' => $invoice->id,
                'amount' => $request->amount,
                'payment_date' => $request->payment_date,
                'payment_method' => $request->payment_method,
                'reference_number' => $request->reference_number,
                'processed_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Payment processed'
            ]);
        } catch (\Exception $e) {

            DB::rollBack();
            throw $e;
        }
    }

    public function deleteInvoice($id)
    {
        try {
            $invoice = InvoiceSupplier::findOrFail($id);
            // if ($invoice->image_invoice) Storage::disk('public')->delete(str_replace('/storage/', '', $invoice->image_invoice));

            if ($invoice->image_invoice) {
                Storage::disk('s3')->delete(
                    str_replace(
                        Storage::disk('s3')->url(''),
                        '',
                        $invoice->image_invoice
                    )
                );
            }

            // if ($invoice->image_proof) Storage::disk('public')->delete(str_replace('/storage/', '', $invoice->image_proof));

            if ($invoice->image_proof) {
                Storage::disk('s3')->delete(
                    str_replace(
                        Storage::disk('s3')->url(''),
                        '',
                        $invoice->image_proof
                    )
                );
            }

            $invoice->delete();
            return response()->json(['message' => 'Invoice deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete invoice.'], 500);
        }
    }
}
