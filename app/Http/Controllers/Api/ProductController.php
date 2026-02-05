<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::with('category')->latest()->get(), 200);
    }

    public function show($id)
    {
        return response()->json(Product::with('category')->findOrFail($id), 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:products',
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'discount_price' => 'nullable|numeric|lt:price',
            'stock' => 'required|integer',
            'image' => 'nullable|image|mimes:jpeg,png,jpg'
        ]);

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        $data = $request->all();
        if ($request->hasFile('image')) {
            // Berubah: Simpan ke disk 's3' dengan akses 'public'
            $path = $request->file('image')->store('products', 's3');
            Storage::disk('s3')->setVisibility($path, 'public');
            $data['image'] = Storage::disk('s3')->url($path); // Simpan URL penuh ke database
        }

        $product = Product::create($data);
        return response()->json($product, 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $data = $request->all();

        if ($request->hasFile('image')) {
            // Berubah: Hapus file lama di S3 jika ada
            if ($product->image) {
                // Kita ambil path relatif dari URL penuh yang tersimpan
                $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->image);
                Storage::disk('s3')->delete($oldPath);
            }

            $path = $request->file('image')->store('products', 's3');
            Storage::disk('s3')->setVisibility($path, 'public');
            $data['image'] = Storage::disk('s3')->url($path);
        }

        $product->update($data);
        return response()->json($product, 200);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        if ($product->image) {
            $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
            Storage::disk('s3')->delete($path);
        }
        $product->delete();
        return response()->json(['message' => 'Product deleted'], 200);
    }
}
