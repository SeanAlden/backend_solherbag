<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use Illuminate\Http\Request;

class CoaController extends Controller
{
    public function index()
    {
        $coas = Coa::with('category')->orderBy('coa_no', 'asc')->get();
        return response()->json($coas);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:coas,name',
            'coa_no' => 'required|string|unique:coas,coa_no',
            'coa_category_id' => 'required|exists:category_coas,id',
            'type' => 'required|in:Debit,Kredit',
            'amount' => 'nullable|numeric',
            'date' => 'nullable|date',
            'description' => 'nullable|string'
        ]);

        $data = $request->all();

        // Auto-assign debit/credit based on type
        if (isset($data['amount'])) {
            if ($data['type'] === 'Debit') {
                $data['debit'] = $data['amount'];
                $data['credit'] = 0;
            } else {
                $data['credit'] = $data['amount'];
                $data['debit'] = 0;
            }
        }

        $coa = Coa::create($data);
        return response()->json($coa, 201);
    }

    public function update(Request $request, $id)
    {
        $coa = Coa::findOrFail($id);

        if ($coa->posted) {
            return response()->json(['message' => 'Cannot edit a posted COA.'], 403);
        }

        $request->validate([
            'name' => 'required|string|unique:coas,name,' . $id,
            'coa_no' => 'required|string|unique:coas,coa_no,' . $id,
            'coa_category_id' => 'required|exists:category_coas,id',
            'type' => 'required|in:Debit,Kredit',
            'amount' => 'nullable|numeric',
            'date' => 'nullable|date',
            'description' => 'nullable|string'
        ]);

        $data = $request->all();

        if (isset($data['amount'])) {
            if ($data['type'] === 'Debit') {
                $data['debit'] = $data['amount'];
                $data['credit'] = 0;
            } else {
                $data['credit'] = $data['amount'];
                $data['debit'] = 0;
            }
        }

        $coa->update($data);
        return response()->json($coa);
    }

    public function destroy($id)
    {
        $coa = Coa::findOrFail($id);

        if ($coa->posted) {
            return response()->json(['message' => 'Cannot delete a posted COA.'], 403);
        }

        $coa->delete();
        return response()->json(['message' => 'COA deleted successfully']);
    }

    // Endpoint khusus untuk memposting Jurnal/COA
    public function postCoa($id)
    {
        $coa = Coa::findOrFail($id);

        if ($coa->posted) {
            return response()->json(['message' => 'COA is already posted.'], 400);
        }

        $coa->update([
            'posted' => 1,
            'posted_date' => now()->toDateString()
        ]);

        return response()->json(['message' => 'COA posted successfully']);
    }
}
