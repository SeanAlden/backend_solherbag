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
    // public function index()
    // {
    //     return response()->json(Product::with('category')->latest()->get(), 200);
    // }


    public function index()
    {
        $products = Product::with('category')
            ->where('status', 'active') // Hanya yang aktif
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

    // public function show($id)
    // {
    //     return response()->json(Product::with('category')->findOrFail($id), 200);
    // }

    // Update fungsi show() agar memuat relasi stocks
    public function show($id)
    {
        return response()->json(Product::with(['category', 'stocks' => function ($q) {
            $q->orderBy('created_at', 'asc');
        }])->findOrFail($id), 200);
    }

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'code' => 'required|unique:products',
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         'discount_price' => 'nullable|numeric|lt:price',
    //         'stock' => 'required|integer',
    //         'image' => 'required|image|max:2048', // 2MB
    //         // [BARU] Validasi multi-image dan video
    //         'variant_images' => 'nullable|array|max:5',
    //         'variant_images.*' => 'image|max:2048', // Tiap gambar maks 2MB
    //         'variant_video' => 'nullable|mimes:mp4,mov,avi|max:5120', // Maks 5MB
    //     ]);

    //     if ($validator->fails()) return response()->json($validator->errors(), 422);

    //     // $data = $request->all();
    //     // if ($request->hasFile('image')) {
    //     //     // Berubah: Simpan ke disk 's3' dengan akses 'public'
    //     //     $path = $request->file('image')->store('products', 's3');
    //     //     Storage::disk('s3')->setVisibility($path, 'public');
    //     //     $data['image'] = Storage::disk('s3')->url($path); // Simpan URL penuh ke database
    //     // }

    //     $data = $request->except(['variant_images', 'variant_video', 'image']);

    //     // 1. Upload Gambar Utama
    //     if ($request->hasFile('image')) {
    //         $path = $request->file('image')->store('products', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['image'] = Storage::disk('s3')->url($path);
    //     }

    //     // 2. Upload Gambar Varian (Array)
    //     $variantImagesUrls = [];
    //     if ($request->hasFile('variant_images')) {
    //         foreach ($request->file('variant_images') as $file) {
    //             $path = $file->store('products/variants', 's3');
    //             Storage::disk('s3')->setVisibility($path, 'public');
    //             $variantImagesUrls[] = Storage::disk('s3')->url($path);
    //         }
    //     }
    //     $data['variant_images'] = count($variantImagesUrls) > 0 ? $variantImagesUrls : null;

    //     // 3. Upload Video
    //     if ($request->hasFile('variant_video')) {
    //         $path = $request->file('variant_video')->store('products/videos', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['variant_video'] = Storage::disk('s3')->url($path);
    //     }

    //     $product = Product::create($data);
    //     return response()->json($product, 201);
    // }

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'code' => 'required|unique:products',
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         'stock' => 'required|integer',

    //         // sekarang URL
    //         'image' => 'required|string',
    //         'variant_images' => 'nullable|array',
    //         'variant_video' => 'nullable|string',
    //     ]);

    //     if ($validator->fails())
    //         return response()->json($validator->errors(), 422);

    //     $product = Product::create($request->all());

    //     return response()->json($product, 201);
    // }

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

        // $product = Product::create($request->all());

        DB::beginTransaction(); // Gunakan transaksi database
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

    // public function update(Request $request, $id)
    // {
    //     $product = Product::findOrFail($id);
    //     // $data = $request->all();

    //     // if ($request->hasFile('image')) {
    //     //     // Berubah: Hapus file lama di S3 jika ada
    //     //     if ($product->image) {
    //     //         // Kita ambil path relatif dari URL penuh yang tersimpan
    //     //         $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //     //         Storage::disk('s3')->delete($oldPath);
    //     //     }

    //     //     $path = $request->file('image')->store('products', 's3');
    //     //     Storage::disk('s3')->setVisibility($path, 'public');
    //     //     $data['image'] = Storage::disk('s3')->url($path);
    //     // }

    //     $data = $request->except(['variant_images', 'variant_video', 'image', '_method']);

    //     // 1. Update Gambar Utama
    //     if ($request->hasFile('image')) {
    //         if ($product->image) {
    //             $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //             Storage::disk('s3')->delete($oldPath);
    //         }
    //         $path = $request->file('image')->store('products', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['image'] = Storage::disk('s3')->url($path);
    //     }

    //     // 2. Update Gambar Varian (Untuk update, kita asumsikan jika ada upload baru, hapus yang lama)
    //     // Jika Anda ingin UX di mana admin bisa menghapus satu persatu, itu butuh endpoint terpisah.
    //     // Untuk saat ini, kita timpa total jika ada file baru diunggah.
    //     if ($request->hasFile('variant_images')) {
    //         if ($product->variant_images) {
    //             foreach ($product->variant_images as $oldImgUrl) {
    //                 $oldPath = str_replace(Storage::disk('s3')->url(''), '', $oldImgUrl);
    //                 Storage::disk('s3')->delete($oldPath);
    //             }
    //         }
    //         $variantImagesUrls = [];
    //         foreach ($request->file('variant_images') as $file) {
    //             $path = $file->store('products/variants', 's3');
    //             Storage::disk('s3')->setVisibility($path, 'public');
    //             $variantImagesUrls[] = Storage::disk('s3')->url($path);
    //         }
    //         $data['variant_images'] = $variantImagesUrls;
    //     }

    //     // 3. Update Video
    //     if ($request->hasFile('variant_video')) {
    //         if ($product->variant_video) {
    //             $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->variant_video);
    //             Storage::disk('s3')->delete($oldPath);
    //         }
    //         $path = $request->file('variant_video')->store('products/videos', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['variant_video'] = Storage::disk('s3')->url($path);
    //     }

    //     $product->update($data);
    //     return response()->json($product, 200);
    // }

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

    // public function destroy($id)
    // {
    //     $product = Product::findOrFail($id);
    //     if ($product->image) {
    //         $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //         Storage::disk('s3')->delete($path);
    //     }
    //     $product->delete();
    //     return response()->json(['message' => 'Product deleted'], 200);
    // }

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
