<?php

namespace App\Http\Controllers;

use Str;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductStock;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('category')
            ->where('status', 'active')
            ->latest()
            ->get();
        return response()->json($products, 200);
    }

    public function inactiveProducts()
    {
        $products = Product::with('category')
            ->where('status', 'inactive')
            ->latest()
            ->get();
        return response()->json($products, 200);
    }

    // Update fungsi show() agar memuat relasi stocks
    public function show($id)
    {
        return response()->json(Product::with(['category', 'stocks' => function ($q) {
            $q->orderBy('created_at', 'asc');
        }])->findOrFail($id), 200);
    }

    public function store(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'code' => 'required|unique:products',
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'stock' => 'required|integer',
            'image' => 'required|string',
            'variant_images' => 'nullable|array',
            'variant_video' => 'nullable|string',
        ]);

        if ($validator->fails())
            return response()->json($validator->errors(), 422);

        DB::beginTransaction(); 
        try {
            $product = Product::create($request->all());

            // [BARU] Buat batch stok pertama kali
            if ($request->stock > 0) {
                $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->stock,
                    'initial_quantity' => $request->stock
                ]);
            }
            // [BARU] BROADCAST KE SEMUA SUBSCRIBER AKTIF
            // Catatan: Di production skala besar, gunakan Mail::to()->queue() agar web admin tidak loading lama.
            $subscribers = \App\Models\Subscriber::where('is_active', true)->pluck('email');

            foreach ($subscribers as $email) {
                try {
                    \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\NewProductAlertMail($product));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Gagal broadcast produk ke $email: " . $e->getMessage());
                    // Lanjut ke email berikutnya jika 1 gagal
                    continue;
                }
            }

            DB::commit();
            return response()->json($product, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => "required|unique:products,code,$id",
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            // 'stock' => 'required|integer',

            'image' => 'nullable|string',
            'variant_images' => 'nullable|array',
            'variant_video' => 'nullable|string',
        ]);

        if ($validator->fails())
            return response()->json($validator->errors(), 422);

        /*
    |--------------------------------------------------------------------------
    | DELETE OLD FILE IF URL CHANGED
    |--------------------------------------------------------------------------
    */

        if ($request->image && $product->image !== $request->image) {
            $oldPath = str_replace(
                Storage::disk('s3')->url(''),
                '',
                $product->image
            );
            Storage::disk('s3')->delete($oldPath);
        }

        if ($request->variant_images) {
            foreach ($product->variant_images ?? [] as $oldImg) {
                if (!in_array($oldImg, $request->variant_images)) {
                    $oldPath = str_replace(
                        Storage::disk('s3')->url(''),
                        '',
                        $oldImg
                    );
                    Storage::disk('s3')->delete($oldPath);
                }
            }
        }

        if (
            $request->variant_video &&
            $product->variant_video !== $request->variant_video
        ) {

            $oldPath = str_replace(
                Storage::disk('s3')->url(''),
                '',
                $product->variant_video
            );

            Storage::disk('s3')->delete($oldPath);
        }

        // $product->update($request->all());

        // [PERBAIKAN] Jangan biarkan 'stock' di-update dari halaman edit
        $data = $request->except(['stock']);
        $product->update($data);

        return response()->json($product, 200);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'inactive']);
        return response()->json(['message' => 'Product deactivated'], 200);
    }

    public function restore($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'active']);
        return response()->json(['message' => 'Product activated'], 200);
    }

    public function forceDelete($id)
    {
        $product = Product::findOrFail($id);
        if ($product->image) {
            $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
            Storage::disk('s3')->delete($path);
        }
        $product->delete();
        return response()->json(['message' => 'Product deleted permanently'], 200);
    }
}
