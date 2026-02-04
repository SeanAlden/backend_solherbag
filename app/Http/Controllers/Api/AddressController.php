<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AddressController extends Controller
{
    //
    public function index(Request $request)
    {
        return $request->user()->addresses;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'region' => 'required',
            'first_name_address' => 'required',
            'last_name_address' => 'required',
            'address_location' => 'required',
            'city' => 'required',
            'province' => 'required',
            'postal_code' => 'required',
        ]);

        // Jika user menandai sebagai default, reset alamat lainnya
        if ($request->is_default) {
            $request->user()->addresses()->update(['is_default' => false]);
        }

        return $request->user()->addresses()->create($request->all());
    }

    public function update(Request $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->update($request->all());
        return $address;
    }

    public function destroy(Request $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
