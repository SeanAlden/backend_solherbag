<?php
//
//namespace App\Http\Controllers;
//
//use Illuminate\Http\Request;
//use App\Http\Controllers\Controller;
//
//class AddressController extends Controller
//{
//    //
//    public function index(Request $request)
//    {
//        return $request->user()->addresses;
//    }
//
//    public function store(Request $request)
//    {
//        $data = $request->validate([
//            'region' => 'required',
//            'first_name_address' => 'required',
//            'last_name_address' => 'required',
//            'address_location' => 'required',
//            'city' => 'required',
//            'province' => 'required',
//            'postal_code' => 'required',
//        ]);
//
//        // Jika user menandai sebagai default, reset alamat lainnya
//        if ($request->is_default) {
//            $request->user()->addresses()->update(['is_default' => false]);
//        }
//
//        return $request->user()->addresses()->create($request->all());
//    }
//
//    public function update(Request $request, $id)
//    {
//        $address = $request->user()->addresses()->findOrFail($id);
//        $address->update($request->all());
//        return $address;
//    }
//
//    public function destroy(Request $request, $id)
//    {
//        $address = $request->user()->addresses()->findOrFail($id);
//        $address->delete();
//        return response()->json(['message' => 'Deleted']);
//    }
//}

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AddressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;

class AddressController extends Controller
{
    protected $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->latest()->get();
        return AddressResource::collection($addresses);
    }

    public function store(AddressRequest $request)
    {
        $address = $this->addressService->createAddress(
            $request->validated(),
            $request->user()
        );

        return new AddressResource($address);
    }

    public function update(AddressRequest $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);

        $updatedAddress = $this->addressService->updateAddress(
            $address,
            $request->validated(),
            $request->user()
        );

        return new AddressResource($updatedAddress);
    }

    public function destroy(Request $request, $id)
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->delete();

        return response()->json(['message' => 'Address successfully deleted']);
    }
}
