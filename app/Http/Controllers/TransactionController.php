<?php

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\Product;
// use App\Models\Payment;
// use Xendit\Configuration;
// use App\Models\Transaction;
// use Illuminate\Support\Str;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Xendit\Refund\RefundApi;
// use Xendit\Invoice\InvoiceApi;
// use Xendit\XenditSdkException;
// use Xendit\Refund\CreateRefund;
// use App\Models\TransactionDetail;
// use App\Services\BiteshipService;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Xendit\Invoice\CreateInvoiceRequest;

// class TransactionController extends Controller
// {
//     public function __construct()
//     {
//         Configuration::setXenditKey(config('services.xendit.secret_key'));
//     }

//     // =================================================================================
//     // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
//     // =================================================================================
//     private function restoreProductStock($productId, $quantityToRestore)
//     {
//         if ($quantityToRestore <= 0) return;

//         // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
//         $product = Product::lockForUpdate()->find($productId);
//         if (!$product) return;

//         $remainingToRestore = $quantityToRestore;

//         // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
//         // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
//         $incompleteBatches = ProductStock::where('product_id', $productId)
//             ->whereColumn('quantity', '<', 'initial_quantity')
//             ->orderBy('created_at', 'asc')
//             ->lockForUpdate() // Kunci baris batch ini selama transaksi berlangsung
//             ->get();

//         foreach ($incompleteBatches as $batch) {
//             if ($remainingToRestore <= 0) break;

//             $spaceAvailable = $batch->initial_quantity - $batch->quantity;

//             if ($spaceAvailable >= $remainingToRestore) {
//                 // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
//                 $batch->increment('quantity', $remainingToRestore);
//                 $remainingToRestore = 0;
//             } else {
//                 // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
//                 $batch->increment('quantity', $spaceAvailable);
//                 $remainingToRestore -= $spaceAvailable;
//             }
//         }

//         // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
//         if ($remainingToRestore > 0) {
//             $latestBatch = ProductStock::where('product_id', $productId)
//                 ->orderBy('created_at', 'desc')
//                 ->lockForUpdate()
//                 ->first();

//             if ($latestBatch) {
//                 // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
//                 $latestBatch->increment('quantity', $remainingToRestore);
//                 $latestBatch->increment('initial_quantity', $remainingToRestore);
//             } else {
//                 // Jika benar-benar tidak ada batch sama sekali, buat batch pengembalian khusus
//                 ProductStock::create([
//                     'product_id' => $productId,
//                     'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
//                     'quantity' => $remainingToRestore,
//                     'initial_quantity' => $remainingToRestore
//                 ]);
//             }
//         }

//         // 4. Kembalikan total stok di tabel master
//         $product->increment('stock', $quantityToRestore);
//     }

//     // --- USER ACTIONS ---
//     // public function checkout(Request $request)
//     // {
//     //     $user = $request->user();
//     //     $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

//     //     if ($cartItems->isEmpty()) {
//     //         return response()->json(['message' => 'Cart is empty'], 400);
//     //     }

//     //     return DB::transaction(function () use ($user, $cartItems, $request) {
//     //         $totalAmount = $cartItems->sum('gross_amount');
//     //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

//     //         // Hitung poin: Misal 1 Poin per Rp 100.000 dari total belanja produk
//     //         $earnedPoints = 0;
//     //         if ($user->is_membership) {
//     //             $earnedPoints = floor($totalAmount / 100000);
//     //         }

//     //         // $transaction = Transaction::create([
//     //         //     'user_id' => $user->id,
//     //         //     'order_id' => $orderId,
//     //         //     'total_amount' => $totalAmount,
//     //         //     'status' => 'awaiting_payment',
//     //         //     'point' => $earnedPoints
//     //         // ]);

//     //         // [PERBAIKAN 1]: Masukkan address_id dan data shipping agar terekam di database!
//     //         $transaction = Transaction::create([
//     //             'user_id' => $user->id,
//     //             'address_id' => $request->address_id, // Pastikan dikirim dari Frontend
//     //             'shipping_method' => $request->shipping_method ?? 'free',
//     //             'shipping_cost' => $request->shipping_cost ?? 0,
//     //             'courier_company' => $request->courier_company,
//     //             'courier_type' => $request->courier_type,
//     //             'delivery_type' => $request->delivery_type ?? 'later',
//     //             'order_id' => $orderId,
//     //             'total_amount' => $totalAmount,
//     //             'status' => 'awaiting_payment',
//     //             'point' => $earnedPoints
//     //         ]);

//     //         foreach ($cartItems as $item) {
//     //             $product = Product::lockForUpdate()->find($item->product_id);

//     //             if ($product->stock < $item->quantity) {
//     //                 throw new \Exception("Stock {$product->name} insufficient");
//     //             }

//     //             TransactionDetail::create([
//     //                 'transaction_id' => $transaction->id,
//     //                 'product_id' => $item->product_id,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => $item->product->discount_price ?? $item->product->price
//     //             ]);

//     //             $product->decrement('stock', $item->quantity);
//     //         }

//     //         Cart::where('user_id', $user->id)->delete();

//     //         return response()->json([
//     //             'transaction_id' => $transaction->id,
//     //             'order_id' => $orderId
//     //         ], 201);
//     //     });
//     // }

//     // --- USER ACTIONS ---
//     // public function checkout(Request $request)
//     // {
//     //     $request->validate([
//     //         'address_id' => 'required',
//     //         'shipping_method' => 'required|in:free,biteship',
//     //         'use_points' => 'nullable|integer|min:0',
//     //         'cart_ids' => 'required|array',          // <-- PASTIKAN DIKIRIM DARI FRONTEND
//     //         'cart_ids.*' => 'exists:carts,id',        // <-- PASTIKAN SEMUA ID VALID
//     //         // ... validasi shipping_cost dll bisa ditaruh di sini
//     //         'shipping_cost',
//     //         'courier_company',
//     //         'courier_type',
//     //         'delivery_type',
//     //         'order_id',
//     //         'total_amount',
//     //         'status',
//     //         'point'
//     //     ]);

//     //     $user = $request->user();
//     //     // $cartItems = Cart::with('product')->where('user_id', $user->id)->get();
//     //     $cartItems = Cart::with('product')
//     //         ->where('user_id', $user->id)
//     //         ->whereIn('id', $request->cart_ids) // <-- KUNCI PENYELESAIAN
//     //         ->get();

//     //     // if ($cartItems->isEmpty()) {
//     //     //     return response()->json(['message' => 'Cart is empty'], 400);
//     //     // }

//     //     if ($cartItems->isEmpty()) {
//     //         return response()->json(['message' => 'No items selected for checkout'], 400);
//     //     }

//     //     return DB::transaction(function () use ($user, $cartItems, $request) {
//     //         $totalAmount = $cartItems->sum('gross_amount');
//     //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

//     //         $earnedPoints = 0;
//     //         if ($user->is_membership) {
//     //             $earnedPoints = floor($totalAmount / 100000);
//     //         }

//     //         // 1. HITUNG DISKON POIN
//     //         $pointsUsed = 0;
//     //         $pointDiscountAmount = 0;
//     //         if ($request->use_points > 0 && $user->is_membership) {
//     //             $pointsUsed = min($request->use_points, $user->point);
//     //             $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
//     //             if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
//     //         }

//     //         // 2. HITUNG ONGKIR
//     //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //         $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //         // 3. BUAT TRANSAKSI (Langsung status PENDING)
//     //         $transaction = Transaction::create([
//     //             'user_id' => $user->id,
//     //             'address_id' => $request->address_id,
//     //             'shipping_method' => $request->shipping_method,
//     //             'shipping_cost' => $totalShippingCost,
//     //             'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//     //             'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//     //             'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//     //             'delivery_date' => $request->delivery_date,
//     //             'delivery_time' => $request->delivery_time,
//     //             'order_id' => $orderId,
//     //             'total_amount' => $totalAmount,
//     //             'status' => 'pending', // LANGSUNG PENDING (Siap Bayar)
//     //             'point' => $earnedPoints
//     //         ]);

//     //         // 4. BUAT DETAIL TRANSAKSI
//     //         $xenditItems = [];
//     //         foreach ($cartItems as $item) {
//     //             $product = Product::lockForUpdate()->find($item->product_id);
//     //             if ($product->stock < $item->quantity) {
//     //                 throw new \Exception("Stock {$product->name} insufficient");
//     //             }

//     //             $price = $item->product->discount_price ?? $item->product->price;

//     //             TransactionDetail::create([
//     //                 'transaction_id' => $transaction->id,
//     //                 'product_id' => $item->product_id,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => $price
//     //             ]);

//     //             $product->decrement('stock', $item->quantity);

//     //             // Siapkan item untuk Xendit
//     //             $xenditItems[] = [
//     //                 'name' => $product->name,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => (int) $price,
//     //                 'category' => 'PHYSICAL_PRODUCT'
//     //             ];
//     //         }

//     //         // 5. HAPUS KERANJANG (HANYA KETIKA CHECKOUT BERHASIL)
//     //         // Cart::where('user_id', $user->id)->delete();
//     //         Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//     //         // 6. GENERATE XENDIT INVOICE DI SINI!
//     //         $externalId = 'PAY-' . $orderId;

//     //         if ($pointDiscountAmount > 0) {
//     //             $xenditItems[] = [
//     //                 'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
//     //                 'quantity' => 1,
//     //                 'price' => -(int) $pointDiscountAmount,
//     //                 'category' => 'DISCOUNT'
//     //             ];
//     //         }

//     //         if ($totalShippingCost > 0) {
//     //             $xenditItems[] = [
//     //                 'name' => 'Shipping Cost (' . $request->courier_company . ')',
//     //                 'quantity' => (int) $totalQuantity,
//     //                 'price' => (int) $baseShippingRate,
//     //                 'category' => 'SHIPPING_FEE'
//     //             ];
//     //         }

//     //         $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

//     //         $invoiceRequest = new CreateInvoiceRequest([
//     //             'external_id' => $externalId,
//     //             'payer_email' => $user->email,
//     //             'amount' => $finalAmount,
//     //             'description' => 'Payment for Order ' . $orderId,
//     //             'items' => $xenditItems,
//     //             'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
//     //             'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
//     //         ]);

//     //         $api = new InvoiceApi();
//     //         $invoice = $api->createInvoice($invoiceRequest);

//     //         Payment::create([
//     //             'transaction_id' => $transaction->id,
//     //             'external_id' => $externalId,
//     //             'checkout_url' => $invoice['invoice_url'],
//     //             'amount' => $totalAmount,
//     //             'status' => 'pending'
//     //         ]);

//     //         // Kembalikan URL Xendit ke Frontend
//     //         return response()->json([
//     //             'checkout_url' => $invoice['invoice_url']
//     //         ], 201);
//     //     });
//     // }

//     // public function checkout(Request $request)
//     // {
//     //     $request->validate([
//     //         'address_id' => 'required',
//     //         'shipping_method' => 'required|in:free,biteship',
//     //         'use_points' => 'nullable|integer|min:0',
//     //         'cart_ids' => 'required|array',
//     //         'cart_ids.*' => 'exists:carts,id',
//     //         'shipping_cost' => 'nullable|numeric',
//     //         'courier_company' => 'nullable|string',
//     //         'courier_type' => 'nullable|string',
//     //         'delivery_type' => 'nullable|string',
//     //     ]);

//     //     $user = $request->user();

//     //     $cartItems = Cart::with('product')
//     //         ->where('user_id', $user->id)
//     //         ->whereIn('id', $request->cart_ids)
//     //         ->get();

//     //     if ($cartItems->isEmpty()) {
//     //         return response()->json(['message' => 'No items selected for checkout'], 400);
//     //     }

//     //     // Mulai Transaksi Database
//     //     return DB::transaction(function () use ($user, $cartItems, $request) {

//     //         $totalAmount = $cartItems->sum('gross_amount');
//     //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

//     //         $earnedPoints = 0;
//     //         if ($user->is_membership) {
//     //             $earnedPoints = floor($totalAmount / 100000);
//     //         }

//     //         // 1. HITUNG DISKON POIN
//     //         $pointsUsed = 0;
//     //         $pointDiscountAmount = 0;
//     //         if ($request->use_points > 0 && $user->is_membership) {
//     //             $pointsUsed = min($request->use_points, $user->point);
//     //             $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
//     //             if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
//     //         }

//     //         // 2. HITUNG ONGKIR
//     //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
//     //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//     //         $totalShippingCost = $baseShippingRate * $totalQuantity;

//     //         // 3. BUAT TRANSAKSI
//     //         $transaction = Transaction::create([
//     //             'user_id' => $user->id,
//     //             'address_id' => $request->address_id,
//     //             'shipping_method' => $request->shipping_method,
//     //             'shipping_cost' => $totalShippingCost,
//     //             'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//     //             'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//     //             'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//     //             'order_id' => $orderId,
//     //             'total_amount' => $totalAmount,
//     //             'status' => 'pending',
//     //             'point' => $earnedPoints
//     //         ]);

//     //         $xenditItems = [];

//     //         // 4. LOOPING KERANJANG - CEK STOK & FIFO REDUCTION DENGAN LOCKING
//     //         foreach ($cartItems as $item) {
//     //             // [PENTING] LockForUpdate memastikan tidak ada race condition!
//     //             $product = Product::lockForUpdate()->find($item->product_id);

//     //             // Validasi Stok Utama
//     //             if ($product->stock < $item->quantity) {
//     //                 throw new \Exception("Stock for '{$product->name}' is insufficient. Available: {$product->stock}");
//     //             }

//     //             $price = $item->product->discount_price ?? $item->product->price;

//     //             TransactionDetail::create([
//     //                 'transaction_id' => $transaction->id,
//     //                 'product_id' => $item->product_id,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => $price
//     //             ]);

//     //             // ========================================================
//     //             // LOGIKA FIFO: PENGURANGAN STOK BERDASARKAN BATCH TERLAMA
//     //             // ========================================================
//     //             $remainingQuantityToDeduct = $item->quantity;

//     //             // Ambil semua batch stok yang masih ada isinya, urutkan dari yang PALING LAMA (created_at ASC)
//     //             $activeBatches = \App\Models\ProductStock::where('product_id', $product->id)
//     //                 ->where('quantity', '>', 0)
//     //                 ->orderBy('created_at', 'asc')
//     //                 // lockForUpdate() agar batch tidak dimodifikasi proses lain
//     //                 ->lockForUpdate()
//     //                 ->get();

//     //             foreach ($activeBatches as $batch) {
//     //                 if ($remainingQuantityToDeduct <= 0) break; // Jika sudah terpenuhi, hentikan loop

//     //                 if ($batch->quantity >= $remainingQuantityToDeduct) {
//     //                     // Batch ini cukup untuk menutupi sisa pesanan
//     //                     $batch->decrement('quantity', $remainingQuantityToDeduct);
//     //                     $remainingQuantityToDeduct = 0;
//     //                 } else {
//     //                     // Batch ini tidak cukup, kurangi semua isinya, lalu sisa pesanan dicarikan di batch berikutnya
//     //                     $remainingQuantityToDeduct -= $batch->quantity;
//     //                     $batch->update(['quantity' => 0]);
//     //                 }
//     //             }

//     //             // Jika setelah melooping semua batch ternyata masih ada sisa (Data tidak sinkron antara master dan batch)
//     //             if ($remainingQuantityToDeduct > 0) {
//     //                 throw new \Exception("System error: Stock batch mismatch for '{$product->name}'. Please contact admin.");
//     //             }

//     //             // Kurangi Total Stok di tabel Master Products
//     //             $product->decrement('stock', $item->quantity);
//     //             // ========================================================

//     //             // Siapkan item untuk Xendit
//     //             $xenditItems[] = [
//     //                 'name' => $product->name,
//     //                 'quantity' => $item->quantity,
//     //                 'price' => (int) $price,
//     //                 'category' => 'PHYSICAL_PRODUCT'
//     //             ];
//     //         }

//     //         // 5. HAPUS KERANJANG
//     //         Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//     //         // 6. GENERATE XENDIT INVOICE
//     //         $externalId = 'PAY-' . $orderId;

//     //         if ($pointDiscountAmount > 0) {
//     //             $xenditItems[] = [
//     //                 'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
//     //                 'quantity' => 1,
//     //                 'price' => -(int) $pointDiscountAmount,
//     //                 'category' => 'DISCOUNT'
//     //             ];
//     //         }

//     //         if ($totalShippingCost > 0) {
//     //             $xenditItems[] = [
//     //                 'name' => 'Shipping Cost (' . $request->courier_company . ')',
//     //                 'quantity' => (int) $totalQuantity,
//     //                 'price' => (int) $baseShippingRate,
//     //                 'category' => 'SHIPPING_FEE'
//     //             ];
//     //         }

//     //         $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

//     //         $invoiceRequest = new CreateInvoiceRequest([
//     //             'external_id' => $externalId,
//     //             'payer_email' => $user->email,
//     //             'amount' => $finalAmount,
//     //             'description' => 'Payment for Order ' . $orderId,
//     //             'items' => $xenditItems,
//     //             'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
//     //             'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
//     //         ]);

//     //         $api = new InvoiceApi();
//     //         $invoice = $api->createInvoice($invoiceRequest);

//     //         Payment::create([
//     //             'transaction_id' => $transaction->id,
//     //             'external_id' => $externalId,
//     //             'checkout_url' => $invoice['invoice_url'],
//     //             'amount' => $totalAmount,
//     //             'status' => 'pending'
//     //         ]);

//     //         return response()->json([
//     //             'checkout_url' => $invoice['invoice_url']
//     //         ], 201);
//     //     });
//     // }

//     // --- USER ACTIONS ---
//     public function checkout(Request $request)
//     {
//         $request->validate([
//             'address_id' => 'required',
//             'shipping_method' => 'required|in:free,biteship',
//             'use_points' => 'nullable|integer|min:0',
//             'cart_ids' => 'required|array',
//             'cart_ids.*' => 'exists:carts,id',
//             'shipping_cost' => 'nullable|numeric',
//             'courier_company' => 'nullable|string',
//             'courier_type' => 'nullable|string',
//             'delivery_type' => 'nullable|string',
//         ]);

//         $user = $request->user();
//         $cartItems = Cart::with('product')
//             ->where('user_id', $user->id)
//             ->whereIn('id', $request->cart_ids)
//             ->get();

//         if ($cartItems->isEmpty()) {
//             return response()->json(['message' => 'No items selected for checkout'], 400);
//         }

//         // [PENTING] Membungkus seluruh checkout dengan DB Transaction (Mencegah Race Condition)
//         return DB::transaction(function () use ($user, $cartItems, $request) {
//             $totalAmount = $cartItems->sum('gross_amount');
//             $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

//             $earnedPoints = 0;
//             if ($user->is_membership) {
//                 $earnedPoints = floor($totalAmount / 100000);
//             }

//             // 1. HITUNG DISKON POIN
//             $pointsUsed = 0;
//             $pointDiscountAmount = 0;
//             if ($request->use_points > 0 && $user->is_membership) {
//                 $pointsUsed = min($request->use_points, $user->point);
//                 $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
//                 if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
//             }

//             // 2. HITUNG ONGKIR
//             $totalQuantity = $cartItems->sum('quantity') ?: 1;
//             $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
//             $totalShippingCost = $baseShippingRate * $totalQuantity;

//             // 3. BUAT TRANSAKSI (Langsung status PENDING)
//             $transaction = Transaction::create([
//                 'user_id' => $user->id,
//                 'address_id' => $request->address_id,
//                 'shipping_method' => $request->shipping_method,
//                 'shipping_cost' => $totalShippingCost,
//                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
//                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
//                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//                 'order_id' => $orderId,
//                 'total_amount' => $totalAmount,
//                 'status' => 'pending',
//                 'point' => $earnedPoints
//             ]);

//             $xenditItems = [];
//             foreach ($cartItems as $item) {

//                 // [PENTING] Kunci baris produk (Anti Race Condition saat stok menipis)
//                 $product = Product::lockForUpdate()->find($item->product_id);
//                 if ($product->stock < $item->quantity) {
//                     throw new \Exception("Stock {$product->name} insufficient");
//                 }

//                 $price = $item->product->discount_price ?? $item->product->price;

//                 TransactionDetail::create([
//                     'transaction_id' => $transaction->id,
//                     'product_id' => $item->product_id,
//                     'quantity' => $item->quantity,
//                     'price' => $price
//                 ]);

//                 // ========================================================
//                 // PENGURANGAN STOK FIFO DARI TABEL BATCH
//                 // ========================================================
//                 $remainingQuantityToDeduct = $item->quantity;

//                 $activeBatches = ProductStock::where('product_id', $product->id)
//                     ->where('quantity', '>', 0)
//                     ->orderBy('created_at', 'asc') // FIFO: Ambil yang tertua
//                     ->lockForUpdate() // Kunci batch ini
//                     ->get();

//                 foreach ($activeBatches as $batch) {
//                     if ($remainingQuantityToDeduct <= 0) break;

//                     if ($batch->quantity >= $remainingQuantityToDeduct) {
//                         $batch->decrement('quantity', $remainingQuantityToDeduct);
//                         $remainingQuantityToDeduct = 0;
//                     } else {
//                         $remainingQuantityToDeduct -= $batch->quantity;
//                         $batch->update(['quantity' => 0]);
//                     }
//                 }

//                 if ($remainingQuantityToDeduct > 0) {
//                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
//                 }

//                 // Kurangi Total Stok Master
//                 $product->decrement('stock', $item->quantity);
//                 // ========================================================

//                 $xenditItems[] = [
//                     'name' => $product->name,
//                     'quantity' => $item->quantity,
//                     'price' => (int) $price,
//                     'category' => 'PHYSICAL_PRODUCT'
//                 ];
//             }

//             // 5. HAPUS KERANJANG
//             Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

//             // 6. GENERATE XENDIT INVOICE
//             $externalId = 'PAY-' . $orderId;

//             if ($pointDiscountAmount > 0) {
//                 $xenditItems[] = [
//                     'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
//                     'quantity' => 1,
//                     'price' => -(int) $pointDiscountAmount,
//                     'category' => 'DISCOUNT'
//                 ];
//             }

//             if ($totalShippingCost > 0) {
//                 $xenditItems[] = [
//                     'name' => 'Shipping Cost (' . $request->courier_company . ')',
//                     'quantity' => (int) $totalQuantity,
//                     'price' => (int) $baseShippingRate,
//                     'category' => 'SHIPPING_FEE'
//                 ];
//             }

//             $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

//             $invoiceRequest = new CreateInvoiceRequest([
//                 'external_id' => $externalId,
//                 'payer_email' => $user->email,
//                 'amount' => $finalAmount,
//                 'description' => 'Payment for Order ' . $orderId,
//                 'items' => $xenditItems,
//                 'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
//                 'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
//             ]);

//             $api = new InvoiceApi();
//             $invoice = $api->createInvoice($invoiceRequest);

//             Payment::create([
//                 'transaction_id' => $transaction->id,
//                 'external_id' => $externalId,
//                 'checkout_url' => $invoice['invoice_url'],
//                 'amount' => $totalAmount,
//                 'status' => 'pending'
//             ]);

//             return response()->json([
//                 'checkout_url' => $invoice['invoice_url']
//             ], 201);
//         });
//     }

//     public function index(Request $request)
//     {
//         // Eager load 'payment' untuk mendapatkan checkout_url
//         $transactions = Transaction::with(['details.product', 'payment', 'address'])
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();
//         return response()->json($transactions);
//     }

//     // Melihat semua transaksi (Sisi Admin)
//     // public function allTransactions()
//     // {
//     //     $transactions = Transaction::with(['user', 'details.product'])
//     //         ->latest()
//     //         ->get();
//     //     return response()->json($transactions);
//     // }

//     public function allTransactions()
//     {
//         // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
//         $transactions = Transaction::with(['user', 'details.product', 'address'])
//             ->latest()
//             ->get();

//         return response()->json($transactions);
//     }

//     // public function cancelOrder(Request $request, $id)
//     // {
//     //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//     //     if (!in_array($transaction->status, ['awaiting_payment', 'pending'])) {
//     //         return response()->json(['message' => 'Cannot cancel this order.'], 400);
//     //     }

//     //     // [BARU] Logika membatalkan pesanan di server Biteship
//     //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//     //         try {
//     //             $response = \Illuminate\Support\Facades\Http::withHeaders([
//     //                 'Authorization' => config('services.biteship.api_key')
//     //             ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//     //             $biteshipData = $response->json();

//     //             // Deteksi jika Biteship menolak pembatalan (misalnya kurir sudah dalam perjalanan / "picking_up")
//     //             if (isset($biteshipData['success']) && $biteshipData['success'] === false) {
//     //                 \Illuminate\Support\Facades\Log::warning('Biteship Cancel Error: ' . json_encode($biteshipData));

//     //                 // Anda bisa memblokir pembatalan lokal jika kurir sudah terlanjur jalan
//     //                 return response()->json([
//     //                     'message' => 'Cannot cancel: Courier is already processing this order. (' . ($biteshipData['error'] ?? 'Logistics error') . ')'
//     //                 ], 400);
//     //             }
//     //         } catch (\Exception $e) {
//     //             \Illuminate\Support\Facades\Log::error('Biteship Cancel Exception: ' . $e->getMessage());
//     //             return response()->json(['message' => 'Failed to connect to logistics provider.'], 500);
//     //         }
//     //     }

//     //     // Update status database lokal
//     //     $transaction->update(['status' => 'cancelled']);
//     //     if ($transaction->payment) {
//     //         $transaction->payment->update(['status' => 'EXPIRED']); // Update status payment lokal
//     //     }

//     //     // Kembalikan stok
//     //     foreach ($transaction->details as $detail) {
//     //         $detail->product->increment('stock', $detail->quantity);
//     //     }

//     //     return response()->json(['message' => 'Order cancelled successfully']);
//     // }

//     // public function cancelOrder(Request $request, $id)
//     // {
//     //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//     //     // [PERBAIKAN 1] Izinkan pembatalan untuk status processing
//     //     if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
//     //         return response()->json(['message' => 'Cannot cancel this order.'], 400);
//     //     }

//     //     // Jika statusnya processing (sudah dibayar), lakukan pre-check ke Biteship
//     //     if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//     //         try {
//     //             $res = \Illuminate\Support\Facades\Http::withHeaders([
//     //                 'Authorization' => config('services.biteship.api_key')
//     //             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//     //             if ($res->successful()) {
//     //                 $data = $res->json();
//     //                 $biteshipStatus = strtolower($data['status'] ?? '');

//     //                 // Jika barang sudah diambil kurir, TOLAK pembatalan
//     //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
//     //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
//     //                     return response()->json([
//     //                         'message' => 'Cannot cancel: The package is already being processed by the courier (Status: ' . strtoupper($biteshipStatus) . ').'
//     //                     ], 400);
//     //                 }

//     //                 // Jika masih aman, batalkan order di Biteship
//     //                 \Illuminate\Support\Facades\Http::withHeaders([
//     //                     'Authorization' => config('services.biteship.api_key')
//     //                 ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
//     //             }
//     //         } catch (\Exception $e) {
//     //             \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Cancel Error: ' . $e->getMessage());
//     //             return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
//     //         }

//     //         // [PENTING] Lakukan proses Refund via Xendit karena statusnya processing (sudah bayar)
//     //         try {
//     //             $transaction->load('payment');
//     //             if ($transaction->payment && $transaction->payment->external_id) {
//     //                 $invoiceApi = new InvoiceApi();
//     //                 $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//     //                 if (!empty($invoices) && count($invoices) > 0) {
//     //                     $xenditInvoiceId = $invoices[0]['id'];
//     //                     $refundApi = new RefundApi();

//     //                     $refundRequest = new CreateRefund([
//     //                         'invoice_id' => $xenditInvoiceId,
//     //                         'reason' => 'REQUESTED_BY_CUSTOMER',
//     //                         'amount' => (int) $transaction->total_amount,
//     //                         'metadata' => ['order_id' => $transaction->order_id]
//     //                     ]);

//     //                     $refundApi->createRefund(null, null, $refundRequest);
//     //                 }
//     //             }
//     //         } catch (\Exception $e) {
//     //             \Illuminate\Support\Facades\Log::error('Auto-Refund on Cancel Error: ' . $e->getMessage());
//     //             // Jika auto-refund gagal, kita ubah statusnya agar admin memprosesnya secara manual
//     //             $transaction->update(['status' => 'refund_manual_required']);

//     //             // Kembalikan stok
//     //             foreach ($transaction->details as $detail) {
//     //                 $detail->product->increment('stock', $detail->quantity);
//     //             }

//     //             return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
//     //         }
//     //     }

//     //     // Update status database lokal (jika bukan processing, atau refund berhasil)
//     //     if ($transaction->status !== 'refund_manual_required') {
//     //         $transaction->update(['status' => 'cancelled']);
//     //     }

//     //     if ($transaction->payment && $transaction->status !== 'refund_manual_required') {
//     //         $transaction->payment->update(['status' => 'EXPIRED']); // Atau REFUNDED jika dari processing
//     //     }

//     //     // Kembalikan stok
//     //     foreach ($transaction->details as $detail) {
//     //         $detail->product->increment('stock', $detail->quantity);
//     //     }

//     //     return response()->json(['message' => 'Order cancelled successfully']);
//     // }

//     public function cancelOrder(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
//             return response()->json(['message' => 'Cannot cancel this order.'], 400);
//         }

//         // PRE-CHECK BITESHIP (Berjalan di luar transaksi database agar tidak memberatkan server)
//         if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//             try {
//                 $res = \Illuminate\Support\Facades\Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key')
//                 ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $data = $res->json();
//                     $biteshipStatus = strtolower($data['status'] ?? '');

//                     $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
//                     if (in_array($biteshipStatus, $unCancellableStatuses)) {
//                         return response()->json([
//                             'message' => 'Cannot cancel: The package is already being processed by the courier.'
//                         ], 400);
//                     }

//                     \Illuminate\Support\Facades\Http::withHeaders([
//                         'Authorization' => config('services.biteship.api_key')
//                     ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
//                 }
//             } catch (\Exception $e) {
//                 return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
//             }

//             // AUTO-REFUND XENDIT
//             try {
//                 $transaction->load('payment');
//                 if ($transaction->payment && $transaction->payment->external_id) {
//                     $invoiceApi = new InvoiceApi();
//                     $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//                     if (!empty($invoices) && count($invoices) > 0) {
//                         $xenditInvoiceId = $invoices[0]['id'];
//                         $refundApi = new RefundApi();

//                         $refundRequest = new CreateRefund([
//                             'invoice_id' => $xenditInvoiceId,
//                             'reason' => 'REQUESTED_BY_CUSTOMER',
//                             'amount' => (int) $transaction->total_amount,
//                             'metadata' => ['order_id' => $transaction->order_id]
//                         ]);

//                         $refundApi->createRefund(null, null, $refundRequest);
//                     }
//                 }
//             } catch (\Exception $e) {
//                 // JIKA REFUND GAGAL (TAPI KURIR SUDAH DIBATALKAN), LEMPAR KE REFUND MANUAL TAPI KEMBALIKAN STOKNYA
//                 DB::transaction(function () use ($transaction) {
//                     $transaction->update(['status' => 'refund_manual_required']);
//                     foreach ($transaction->details as $detail) {
//                         // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
//                         $this->restoreProductStock($detail->product_id, $detail->quantity);
//                     }
//                 });

//                 return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
//             }
//         }

//         // [PENTING] Bungkus pembatalan status dan pengembalian stok dalam DB Transaction
//         DB::transaction(function () use ($transaction) {
//             // Re-fetch dan Lock untuk mencegah error paralel
//             $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

//             if ($lockedTransaction->status !== 'refund_manual_required') {
//                 $lockedTransaction->update(['status' => 'cancelled']);
//             }

//             if ($lockedTransaction->payment && $lockedTransaction->status !== 'refund_manual_required') {
//                 $lockedTransaction->payment->update(['status' => 'EXPIRED']);
//             }

//             // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
//             foreach ($lockedTransaction->details as $detail) {
//                 $this->restoreProductStock($detail->product_id, $detail->quantity);
//             }
//         });

//         return response()->json(['message' => 'Order cancelled successfully']);
//     }

//     public function confirmComplete(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if ($transaction->status !== 'processing') {
//             return response()->json(['message' => 'Order cannot be completed yet.'], 400);
//         }

//         $transaction->update(['status' => 'completed']);

//         // [PERBAIKAN] Cek syarat membership setelah admin komplit manual
//         $this->checkAndAssignMembership($transaction->user);

//         return response()->json(['message' => 'Order completed!']);
//     }

//     // public function requestRefund(Request $request, $id)
//     // {
//     //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//     //     // Refund bisa diajukan saat status ini
//     //     if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
//     //         return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
//     //     }

//     //     $transaction->update(['status' => 'refund_requested']);
//     //     return response()->json(['message' => 'Refund requested. Waiting for admin approval.']);
//     // }

//     public function requestRefund(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         // Validasi: Refund hanya bisa diajukan saat pesanan selesai atau gagal kirim
//         if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
//             return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
//         }

//         // [BARU] Validasi input text dan file bukti (gambar atau video)
//         $request->validate([
//             'reason' => 'required|string|max:1000',
//             'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240' // Max 10MB
//         ]);

//         try {
//             // [BARU] Upload file ke AWS S3
//             $file = $request->file('proof_file');
//             $path = $file->store('refund_proofs', [
//                 'disk' => 's3',
//                 'visibility' => 'public'
//             ]);
//             $proofUrl = \Illuminate\Support\Facades\Storage::disk('s3')->url($path);

//             // Update transaksi
//             $transaction->update([
//                 'status' => 'refund_requested',
//                 'refund_reason' => $request->reason,
//                 'refund_proof_url' => $proofUrl
//             ]);

//             return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

//         } catch (\Exception $e) {
//             \Illuminate\Support\Facades\Log::error('Failed to upload refund proof: ' . $e->getMessage());
//             return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
//         }
//     }

//     // User klik "Refund Now" setelah disetujui admin
//     // public function processRefundUser(Request $request, $id)
//     // {
//     //     // 1. Validasi Transaksi Lokal
//     //     $transaction = Transaction::with('payment')
//     //         ->where('user_id', $request->user()->id)
//     //         ->findOrFail($id);

//     //     if ($transaction->status !== 'refund_approved') {
//     //         return response()->json(['message' => 'Refund not approved yet.'], 400);
//     //     }

//     //     if (!$transaction->payment) {
//     //         return response()->json(['message' => 'Payment data not found.'], 404);
//     //     }

//     //     // --- PRE-CHECK: Validasi Status Kurir Biteship SEBELUM Refund ---
//     //     $shouldCancelCourier = false;
//     //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//     //         try {
//     //             $res = \Illuminate\Support\Facades\Http::withHeaders([
//     //                 'Authorization' => config('services.biteship.api_key')
//     //             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//     //             if ($res->successful()) {
//     //                 $data = $res->json();
//     //                 $biteshipStatus = strtolower($data['status'] ?? '');

//     //                 // Daftar status di mana pesanan SUDAH TIDAK BISA dibatalkan
//     //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected'];

//     //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
//     //                     // CEGAH REFUND JIKA KURIR SUDAH JALAN/SELESAI
//     //                     return response()->json([
//     //                         'message' => 'Cannot process refund: The package is already in transit or delivered (Status: ' . strtoupper($biteshipStatus) . '). Please return the item first.'
//     //                     ], 400);
//     //                 }

//     //                 // Jika status masih aman (placed, allocated, picking_up), tandai untuk dibatalkan nanti
//     //                 if (!in_array($biteshipStatus, ['cancelled'])) {
//     //                     $shouldCancelCourier = true;
//     //                 }
//     //             }
//     //         } catch (\Exception $e) {
//     //             // Jika API Biteship down, kita harus berhati-hati.
//     //             // Untuk keamanan, batalkan proses refund jika kita tidak bisa memastikan status kurir.
//     //             \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
//     //             return response()->json([
//     //                 'message' => 'Failed to verify logistics status with Biteship. Please try again later.'
//     //             ], 500);
//     //         }
//     //     }

//     //     // --- EKSEKUSI REFUND KE XENDIT ---
//     //     try {
//     //         // STEP A: Cari Invoice ID
//     //         $invoiceApi = new InvoiceApi();
//     //         $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//     //         if (empty($invoices) || count($invoices) === 0) {
//     //             throw new \Exception("Invoice not found in Xendit.");
//     //         }

//     //         $xenditInvoiceId = $invoices[0]['id'];

//     //         // STEP B: Coba Refund via API
//     //         $refundApi = new RefundApi();

//     //         $refundRequest = new CreateRefund([
//     //             'invoice_id' => $xenditInvoiceId,
//     //             'reason' => 'REQUESTED_BY_CUSTOMER',
//     //             'amount' => (int) $transaction->total_amount,
//     //             'metadata' => [
//     //                 'order_id' => $transaction->order_id
//     //             ]
//     //         ]);

//     //         $result = $refundApi->createRefund(null, null, $refundRequest);

//     //         // Jika Xendit sukses, update database
//     //         DB::transaction(function () use ($transaction) {
//     //             $transaction->update(['status' => 'refunded']);
//     //             if ($transaction->payment) {
//     //                 $transaction->payment->update(['status' => 'REFUNDED']);
//     //             }
//     //         });

//     //         // STEP C: Batalkan Kurir Biteship (Karena kita sudah pastikan statusnya aman untuk dicancel)
//     //         if ($shouldCancelCourier) {
//     //             \Illuminate\Support\Facades\Http::withHeaders([
//     //                 'Authorization' => config('services.biteship.api_key')
//     //             ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
//     //         }

//     //         return response()->json([
//     //             'message' => 'Refund processed successfully. Funds returned automatically.',
//     //             'type' => 'automatic'
//     //         ]);
//     //     } catch (XenditSdkException $e) {
//     //         // --- Handling Khusus Jika Channel Tidak Support Refund ---
//     //         $errorMessage = $e->getMessage();

//     //         if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
//     //             // Update status menjadi 'refund_manual_required'
//     //             $transaction->update(['status' => 'refund_manual_required']);

//     //             // Tetap batalkan kurir Biteship karena pesanan ini di-hold untuk refund manual
//     //             if ($shouldCancelCourier) {
//     //                 \Illuminate\Support\Facades\Http::withHeaders([
//     //                     'Authorization' => config('services.biteship.api_key')
//     //                 ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
//     //             }

//     //             return response()->json([
//     //                 'message' => 'Automatic refund not supported for this payment method. Status updated to Manual Check.',
//     //                 'code' => 'MANUAL_REFUND_NEEDED'
//     //             ], 200);
//     //         }

//     //         \Illuminate\Support\Facades\Log::error('Xendit Refund Error: ' . $errorMessage);
//     //         return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
//     //     } catch (\Exception $e) {
//     //         \Illuminate\Support\Facades\Log::error('System Refund Error: ' . $e->getMessage());
//     //         return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
//     //     }
//     // }

//     public function processRefundUser(Request $request, $id)
//     {
//         $transaction = Transaction::with('payment')
//             ->where('user_id', $request->user()->id)
//             ->findOrFail($id);

//         if ($transaction->status !== 'refund_approved') {
//             return response()->json(['message' => 'Refund not approved yet.'], 400);
//         }

//         if (!$transaction->payment) {
//             return response()->json(['message' => 'Payment data not found.'], 404);
//         }

//         // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR (DILAKUKAN PERTAMA) ---
//         if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//             try {
//                 $res = \Illuminate\Support\Facades\Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key')
//                 ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//                 if ($res->successful()) {
//                     $data = $res->json();
//                     $biteshipStatus = strtolower($data['status'] ?? '');

//                     $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

//                     if (in_array($biteshipStatus, $unCancellableStatuses)) {
//                         return response()->json([
//                             'message' => 'Cannot process refund: The package is already in transit or has issues (Status: ' . strtoupper($biteshipStatus) . '). Please contact logistics.'
//                         ], 400);
//                     }

//                     // JIKA AMAN, BATALKAN KURIR SEKARANG JUGA
//                     if (!in_array($biteshipStatus, ['cancelled'])) {
//                         $cancelRes = \Illuminate\Support\Facades\Http::withHeaders([
//                             'Authorization' => config('services.biteship.api_key')
//                         ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//                         $cancelData = $cancelRes->json();
//                         if (isset($cancelData['success']) && $cancelData['success'] === false) {
//                             return response()->json([
//                                 'message' => 'Failed to cancel courier. Refund aborted to prevent loss.'
//                             ], 400);
//                         }
//                     }
//                 }
//             } catch (\Exception $e) {
//                 \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
//                 return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
//             }
//         }

//         // --- JIKA KURIR BERHASIL DIBATALKAN, BARU KEMBALIKAN UANGNYA ---
//         // try {
//         //     $invoiceApi = new InvoiceApi();
//         //     $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//         //     if (empty($invoices) || count($invoices) === 0) {
//         //         throw new \Exception("Invoice not found in Xendit.");
//         //     }

//         //     $xenditInvoiceId = $invoices[0]['id'];
//         //     $refundApi = new RefundApi();

//         //     $refundRequest = new CreateRefund([
//         //         'invoice_id' => $xenditInvoiceId,
//         //         'reason' => 'REQUESTED_BY_CUSTOMER',
//         //         'amount' => (int) $transaction->total_amount,
//         //         'metadata' => ['order_id' => $transaction->order_id]
//         //     ]);

//         //     $result = $refundApi->createRefund(null, null, $refundRequest);

//         //     // Jika Xendit sukses, update database lokal
//         //     DB::transaction(function () use ($transaction) {
//         //         $transaction->update(['status' => 'refunded']);
//         //         if ($transaction->payment) {
//         //             $transaction->payment->update(['status' => 'REFUNDED']);
//         //         }

//         //         // Pastikan user adalah member dan transaksi ini sebelumnya menghasilkan poin
//         //         if ($transaction->point > 0 && $transaction->user->is_membership) {
//         //             // Cegah poin user menjadi minus jika dia sudah terlanjur memakainya
//         //             $currentPoints = $transaction->user->point;
//         //             $pointsToDeduct = min($currentPoints, $transaction->point);

//         //             if ($pointsToDeduct > 0) {
//         //                 $transaction->user->decrement('point', $pointsToDeduct);
//         //             }

//         //             // Nolkan poin di transaksi agar tidak ditarik ganda di masa depan
//         //             $transaction->update(['point' => 0]);
//         //         }
//         //     });

//         //     return response()->json([
//         //         'message' => 'Refund processed successfully. Funds returned automatically.',
//         //         'type' => 'automatic'
//         //     ]);
//         // } catch (XenditSdkException $e) {
//         //     $errorMessage = $e->getMessage();

//         //     if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
//         //         // Kurir sudah dibatalkan di atas, jadi aman untuk mengubah ke manual_required
//         //         $transaction->update(['status' => 'refund_manual_required']);

//         //         return response()->json([
//         //             'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
//         //             'code' => 'MANUAL_REFUND_NEEDED'
//         //         ], 200);
//         //     }

//         //     \Illuminate\Support\Facades\Log::error('Xendit Refund Error: ' . $errorMessage);
//         //     return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
//         // } catch (\Exception $e) {
//         //     \Illuminate\Support\Facades\Log::error('System Refund Error: ' . $e->getMessage());
//         //     return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
//         // }

//         // --- EKSEKUSI REFUND KE XENDIT ---
//         try {
//             $invoiceApi = new InvoiceApi();
//             $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

//             if (empty($invoices) || count($invoices) === 0) {
//                 throw new \Exception("Invoice not found in Xendit.");
//             }

//             $xenditInvoiceId = $invoices[0]['id'];
//             $refundApi = new RefundApi();

//             $refundRequest = new CreateRefund([
//                 'invoice_id' => $xenditInvoiceId,
//                 'reason' => 'REQUESTED_BY_CUSTOMER',
//                 'amount' => (int) $transaction->total_amount,
//                 'metadata' => ['order_id' => $transaction->order_id]
//             ]);

//             $refundApi->createRefund(null, null, $refundRequest);

//             // [PENTING] Jika Xendit sukses, update DB & Kembalikan Stok FIFO dalam 1 Transaksi
//             DB::transaction(function () use ($transaction) {
//                 $transaction->update(['status' => 'refunded']);
//                 if ($transaction->payment) {
//                     $transaction->payment->update(['status' => 'REFUNDED']);
//                 }

//                 if ($transaction->point > 0 && $transaction->user->is_membership) {
//                     $currentPoints = $transaction->user->point;
//                     $pointsToDeduct = min($currentPoints, $transaction->point);
//                     if ($pointsToDeduct > 0) {
//                         $transaction->user->decrement('point', $pointsToDeduct);
//                     }
//                     $transaction->update(['point' => 0]);
//                 }

//                 // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore saat sukses direfund
//                 foreach ($transaction->details as $detail) {
//                     $this->restoreProductStock($detail->product_id, $detail->quantity);
//                 }
//             });

//             return response()->json([
//                 'message' => 'Refund processed successfully. Funds returned automatically.',
//                 'type' => 'automatic'
//             ]);
//         } catch (XenditSdkException $e) {
//             $errorMessage = $e->getMessage();

//             if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
//                 // [PENTING] Karena manual refund, stok juga kita kembalikan sekarang karena barangnya batal terkirim
//                 DB::transaction(function () use ($transaction) {
//                     $transaction->update(['status' => 'refund_manual_required']);

//                     foreach ($transaction->details as $detail) {
//                         $this->restoreProductStock($detail->product_id, $detail->quantity);
//                     }
//                 });

//                 return response()->json([
//                     'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
//                     'code' => 'MANUAL_REFUND_NEEDED'
//                 ], 200);
//             }

//             return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
//         }
//     }

//     public function approveRefund($id)
//     {
//         $transaction = Transaction::findOrFail($id);
//         if ($transaction->status !== 'refund_requested') {
//             return response()->json(['message' => 'Invalid status'], 400);
//         }

//         $transaction->update(['status' => 'refund_approved']);
//         return response()->json(['message' => 'Refund request approved.']);
//     }

//     public function rejectRefund($id)
//     {
//         $transaction = Transaction::findOrFail($id);
//         if ($transaction->status !== 'refund_requested') {
//             return response()->json(['message' => 'Invalid status'], 400);
//         }

//         $transaction->update(['status' => 'refund_rejected']);
//         return response()->json(['message' => 'Refund request rejected.']);
//     }

//     // Show single transaction
//     public function show($id)
//     {
//         return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
//     }

//     public function adminShow($id)
//     {
//         // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
//         $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
//             ->findOrFail($id);

//         return response()->json($transaction);
//     }

//     // public function salesReport(Request $request)
//     // {
//     //     $month = $request->query('month'); // Format: 1-12
//     //     $year = $request->query('year');   // Format: YYYY
//     //     $search = $request->query('search'); // Pencarian nama produk
//     //     $perPage = $request->query('per_page', 10);

//     //     $query = TransactionDetail::query()
//     //         ->select(
//     //             'products.id',
//     //             'products.code',
//     //             'products.name',
//     //             'products.image',
//     //             'categories.name as category_name',
//     //             DB::raw('SUM(transaction_details.quantity) as total_sold'),
//     //             DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
//     //         )
//     //         ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//     //         ->join('products', 'products.id', '=', 'transaction_details.product_id')
//     //         ->join('categories', 'categories.id', '=', 'products.category_id')
//     //         ->whereIn('transactions.status', ['completed', 'refund_rejected']);

//     //     // Filter Bulan & Tahun
//     //     if ($month && $year) {
//     //         $query->whereMonth('transactions.created_at', $month)
//     //             ->whereYear('transactions.created_at', $year);
//     //     } elseif ($year) {
//     //         $query->whereYear('transactions.created_at', $year);
//     //     }

//     //     // Filter Pencarian (Nama Produk atau Kode)
//     //     if ($search) {
//     //         $query->where(function ($q) use ($search) {
//     //             $q->where('products.name', 'like', "%{$search}%")
//     //                 ->orWhere('products.code', 'like', "%{$search}%");
//     //         });
//     //     }

//     //     // Grouping & Ordering
//     //     $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
//     //         ->orderByDesc('total_revenue') // Urutkan dari omzet tertinggi
//     //         ->paginate($perPage);

//     //     return response()->json($report);
//     // }

//     public function salesReport(Request $request)
//     {
//         $month = $request->query('month');
//         $year = $request->query('year');
//         $search = $request->query('search');

//         $query = TransactionDetail::query()
//             ->select(
//                 'products.id',
//                 'products.code',
//                 'products.name',
//                 'products.image',
//                 'categories.name as category_name',
//                 DB::raw('SUM(transaction_details.quantity) as total_sold'),
//                 DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
//             )
//             ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//             ->join('products', 'products.id', '=', 'transaction_details.product_id')
//             ->join('categories', 'categories.id', '=', 'products.category_id')
//             ->whereIn('transactions.status', ['completed', 'refund_rejected']);

//         if ($month && $year) {
//             $query->whereMonth('transactions.created_at', $month)
//                 ->whereYear('transactions.created_at', $year);
//         } elseif ($year) {
//             $query->whereYear('transactions.created_at', $year);
//         }

//         if ($search) {
//             $query->where(function ($q) use ($search) {
//                 $q->where('products.name', 'like', "%{$search}%")
//                     ->orWhere('products.code', 'like', "%{$search}%");
//             });
//         }

//         // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
//         $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
//             ->orderByDesc('total_revenue')
//             ->get();

//         return response()->json([
//             'data' => $report // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
//         ]);
//     }

//     public function trackOrder($id)
//     {
//         $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

//         // [PERBAIKAN] Validasi menggunakan biteship_order_id
//         if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
//             return response()->json(['message' => 'Tracking information is not available yet.'], 400);
//         }

//         try {
//             // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
//             $response = \Illuminate\Support\Facades\Http::withHeaders([
//                 'Authorization' => config('services.biteship.api_key')
//             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//             $data = $response->json();

//             if (isset($data['success']) && $data['success'] === false) {
//                 return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
//             }

//             // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
//             return response()->json($data);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
//         }
//     }

//     public function bulkTrackOrders(Request $request)
//     {
//         $request->validate([
//             'transaction_ids' => 'required|array',
//             'transaction_ids.*' => 'integer|exists:transactions,id'
//         ]);

//         // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
//         $transactions = Transaction::where('user_id', $request->user()->id)
//             ->whereIn('id', $request->transaction_ids)
//             ->whereNotNull('biteship_order_id')
//             ->where('shipping_method', 'biteship')
//             ->get();

//         $trackingData = [];

//         // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
//         foreach ($transactions as $transaction) {
//             try {
//                 $response = \Illuminate\Support\Facades\Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key')
//                 ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//                 if (isset($response['success']) && $response['success'] === true) {
//                     $trackingData[$transaction->id] = $response->json();
//                 } else {
//                     $trackingData[$transaction->id] = ['status' => 'pending']; // Fallback jika belum teralokasi
//                 }
//             } catch (\Exception $e) {
//                 // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
//                 $trackingData[$transaction->id] = ['status' => 'error fetching data'];
//             }
//         }

//         // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
//         return response()->json($trackingData);
//     }

//     // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
//     public function adminBulkTrackOrders(Request $request)
//     {
//         $request->validate([
//             'transaction_ids' => 'required|array',
//             'transaction_ids.*' => 'integer|exists:transactions,id'
//         ]);

//         // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
//         $transactions = Transaction::whereIn('id', $request->transaction_ids)
//             ->whereNotNull('biteship_order_id')
//             ->where('shipping_method', 'biteship')
//             ->get();

//         $trackingData = [];

//         foreach ($transactions as $transaction) {
//             try {
//                 $response = \Illuminate\Support\Facades\Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key')
//                 ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//                 if (isset($response['success']) && $response['success'] === true) {
//                     $trackingData[$transaction->id] = $response->json();
//                 } else {
//                     $trackingData[$transaction->id] = ['status' => 'pending'];
//                 }
//             } catch (\Exception $e) {
//                 $trackingData[$transaction->id] = ['status' => 'error fetching data'];
//             }
//         }

//         return response()->json($trackingData);
//     }

//     // Fungsi khusus Admin untuk mengambil detail tracking 1 order
//     public function adminTrackOrder($id)
//     {
//         $transaction = Transaction::findOrFail($id); // HAPUS filter user_id

//         if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
//             return response()->json(['message' => 'Tracking information is not available yet.'], 400);
//         }

//         try {
//             $response = \Illuminate\Support\Facades\Http::withHeaders([
//                 'Authorization' => config('services.biteship.api_key')
//             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

//             $data = $response->json();

//             if (isset($data['success']) && $data['success'] === false) {
//                 return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
//             }

//             return response()->json($data);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
//         }
//     }

//     public function printLabel(Request $request, $id)
//     {
//         $transaction = Transaction::findOrFail($id);

//         if (!$transaction->biteship_order_id) {
//             return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
//         }

//         // Ambil query parameter dari Vue (insurance_shown, dll)
//         $queryString = http_build_query($request->all());

//         // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
//         $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

//         try {
//             // Tembak URL label Biteship dengan API Key kita
//             $response = \Illuminate\Support\Facades\Http::withHeaders([
//                 'Authorization' => config('services.biteship.api_key')
//             ])->get($biteshipUrl);

//             // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
//             if ($response->successful()) {
//                 return response($response->body(), 200)
//                     ->header('Content-Type', 'application/pdf')
//                     ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
//             }

//             return response()->json(['message' => 'Gagal mengambil resi dari Biteship: ' . $response->body()], 400);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
//         }
//     }

//     // public function biteshipCallback(Request $request)
//     // {
//     //     // Biteship mengirimkan token otentikasi di Header untuk keamanan
//     //     $biteshipSignature = $request->header('biteship-signature');
//     //     // Validasi signature jika perlu (Opsional tapi disarankan di Production)

//     //     $event = $request->input('event'); // Contoh: 'order.status.updated' atau 'waybill.ready'
//     //     $biteshipOrderId = $request->input('order_id');
//     //     $status = $request->input('status'); // picking_up, dropped, delivered, dll
//     //     $waybill = $request->input('courier_tracking_id'); // Ini adalah resi

//     //     \Log::info('Biteship Webhook Received: ', $request->all());

//     //     $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

//     //     if (!$transaction) {
//     //         return response()->json(['message' => 'Transaction not found'], 200);
//     //     }

//     //     // 1. Jika ada update Nomor Resi yang menyusul
//     //     if ($waybill && $transaction->tracking_number === 'Pending') {
//     //         $transaction->update(['tracking_number' => $waybill]);
//     //     }

//     //     // 2. Jika Anda ingin Auto-Complete transaksi saat kurir mengubah status jadi 'delivered'
//     //     // (Ini opsional, karena Anda sudah punya tombol "Order Received" untuk ditekan user)
//     //     if ($status === 'delivered' && $transaction->status === 'processing') {
//     //         $transaction->update(['status' => 'completed']);
//     //     }

//     //     return response()->json(['message' => 'Webhook processed']);
//     // }

//     // public function biteshipCallback(Request $request)
//     // {
//     //     // Validasi signature (Opsional tapi disarankan)
//     //     $biteshipSignature = $request->header('biteship-signature');

//     //     $biteshipOrderId = $request->input('order_id');
//     //     $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected
//     //     $waybill = $request->input('courier_waybill_id');

//     //     \Log::info('Biteship Webhook Received: ', $request->all());

//     //     $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

//     //     if (!$transaction) {
//     //         return response()->json(['message' => 'Transaction not found'], 200);
//     //     }

//     //     // 1. Update Resi jika baru turun
//     //     if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
//     //         $transaction->update(['tracking_number' => $waybill]);
//     //     }

//     //     // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
//     //     if ($status === 'delivered' && $transaction->status === 'processing') {
//     //         $transaction->update(['status' => 'completed']);
//     //     }

//     //     // 3. [BARU] Jika logistik membatalkan pengiriman SEPIHAK (sebelum sampai ke pembeli)
//     //     // Hal ini biasanya terjadi jika kurir tidak menemukan alamat origin, paket terlalu besar, dll.
//     //     if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
//     //         // Ubah status ke manual refund required, karena pembeli sudah bayar, tapi barang gagal jalan.
//     //         // Admin harus mengecek mengapa logistik gagal, lalu me-refund manual atau memesan kurir ulang.
//     //         $transaction->update([
//     //             'status' => 'refund_manual_required',
//     //             'tracking_number' => 'Logistics Cancelled/Rejected'
//     //         ]);
//     //         \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
//     //     }

//     //     if ($status === 'disposed' && $transaction->status === 'processing') {
//     //         // Ubah status ke shipping failed, karena pembeli sudah bayar, tapi barang rusak di tengah jalan.
//     //         // Admin harus mengembalikan uang pembeli.
//     //         $transaction->update([
//     //             'status' => 'shipping_failed',
//     //             'tracking_number' => 'Shipping Failed'
//     //         ]);
//     //         \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
//     //     }

//     //     if ($status === 'returned' && $transaction->status === 'processing') {
//     //         // Ubah status ke returned, karena user tidak jadi membeli dan barang telah dikembalikan.
//     //         // Admin harus mengembalikan uang pembeli.
//     //         $transaction->update([
//     //             'status' => 'returned',
//     //             'tracking_number' => 'Shipping Returned'
//     //         ]);
//     //         \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
//     //     }

//     //     return response()->json(['message' => 'Webhook processed successfully']);
//     // }

//     public function biteshipCallback(Request $request)
//     {
//         // Validasi signature (Opsional tapi disarankan)
//         $biteshipSignature = $request->header('biteship-signature');

//         $biteshipOrderId = $request->input('order_id');
//         $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
//         $waybill = $request->input('courier_waybill_id');

//         \Log::info('Biteship Webhook Received: ', $request->all());

//         $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

//         if (!$transaction) {
//             return response()->json(['message' => 'Transaction not found'], 200);
//         }

//         // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
//         $updates = ['shipping_status' => $status];

//         // 1. Update Resi jika baru turun
//         if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
//             $updates['tracking_number'] = $waybill;
//         }

//         // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
//         if ($status === 'delivered' && $transaction->status === 'processing') {
//             $updates['status'] = 'completed';

//             // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
//             $transaction->update($updates);

//             // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
//             $this->checkAndAssignMembership($transaction->user);

//             // Refresh data user
//             $transaction->user->refresh();

//             // Tambah poin user jika dia member dan transaksi punya poin
//             if ($transaction->point > 0 && $transaction->user->is_membership) {
//                 $transaction->user->increment('point', $transaction->point);
//             }

//             return response()->json(['message' => 'Webhook processed and membership checked']);
//         }

//         // 3. Jika logistik membatalkan pengiriman SEPIHAK
//         if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
//             $updates['status'] = 'refund_manual_required';
//             $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
//             \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
//         }

//         if ($status === 'disposed' && $transaction->status === 'processing') {
//             $updates['status'] = 'shipping_failed';
//             $updates['tracking_number'] = 'Shipping Failed';
//             \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
//         }

//         if ($status === 'returned' && $transaction->status === 'processing') {
//             $updates['status'] = 'returned';
//             $updates['tracking_number'] = 'Shipping Returned';
//             \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
//         }

//         // Eksekusi semua update ke database dalam 1 query
//         $transaction->update($updates);

//         return response()->json(['message' => 'Webhook processed successfully']);
//     }

//     // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
//     private function checkAndAssignMembership($user)
//     {
//         // Jika user sudah member, tidak perlu cek lagi
//         if ($user->is_membership) return;

//         // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
//         $totalSpent = Transaction::where('user_id', $user->id)
//             ->where('status', 'completed')
//             ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

//         // Jika total belanja >= 100.000, jadikan member
//         if ($totalSpent >= 100000) {
//             $user->update(['is_membership' => true]);
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Payment;
use Xendit\Configuration;
use App\Models\Transaction;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Xendit\Refund\RefundApi;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Xendit\Refund\CreateRefund;
use App\Models\TransactionDetail;
use App\Services\BiteshipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Xendit\Invoice\CreateInvoiceRequest;

class TransactionController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    // =================================================================================
    // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
    // =================================================================================
    private function restoreProductStock($productId, $quantityToRestore)
    {
        if ($quantityToRestore <= 0) return;

        // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
        $product = Product::lockForUpdate()->find($productId);
        if (!$product) return;

        $remainingToRestore = $quantityToRestore;

        // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
        // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate() // Kunci baris batch ini selama transaksi berlangsung
            ->get();

        foreach ($incompleteBatches as $batch) {
            if ($remainingToRestore <= 0) break;

            $spaceAvailable = $batch->initial_quantity - $batch->quantity;

            if ($spaceAvailable >= $remainingToRestore) {
                // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
                $batch->increment('quantity', $remainingToRestore);
                $remainingToRestore = 0;
            } else {
                // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
                $batch->increment('quantity', $spaceAvailable);
                $remainingToRestore -= $spaceAvailable;
            }
        }

        // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id', $productId)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($latestBatch) {
                // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
                $latestBatch->increment('quantity', $remainingToRestore);
                $latestBatch->increment('initial_quantity', $remainingToRestore);
            } else {
                // Jika benar-benar tidak ada batch sama sekali, buat batch pengembalian khusus
                ProductStock::create([
                    'product_id' => $productId,
                    'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore
                ]);
            }
        }

        // 4. Kembalikan total stok di tabel master
        $product->increment('stock', $quantityToRestore);
    }

    // --- USER ACTIONS ---
    // public function checkout(Request $request)
    // {
    //     $user = $request->user();
    //     $cartItems = Cart::with('product')->where('user_id', $user->id)->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['message' => 'Cart is empty'], 400);
    //     }

    //     return DB::transaction(function () use ($user, $cartItems, $request) {
    //         $totalAmount = $cartItems->sum('gross_amount');
    //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

    //         // Hitung poin: Misal 1 Poin per Rp 100.000 dari total belanja produk
    //         $earnedPoints = 0;
    //         if ($user->is_membership) {
    //             $earnedPoints = floor($totalAmount / 100000);
    //         }

    //         // $transaction = Transaction::create([
    //         //     'user_id' => $user->id,
    //         //     'order_id' => $orderId,
    //         //     'total_amount' => $totalAmount,
    //         //     'status' => 'awaiting_payment',
    //         //     'point' => $earnedPoints
    //         // ]);

    //         // [PERBAIKAN 1]: Masukkan address_id dan data shipping agar terekam di database!
    //         $transaction = Transaction::create([
    //             'user_id' => $user->id,
    //             'address_id' => $request->address_id, // Pastikan dikirim dari Frontend
    //             'shipping_method' => $request->shipping_method ?? 'free',
    //             'shipping_cost' => $request->shipping_cost ?? 0,
    //             'courier_company' => $request->courier_company,
    //             'courier_type' => $request->courier_type,
    //             'delivery_type' => $request->delivery_type ?? 'later',
    //             'order_id' => $orderId,
    //             'total_amount' => $totalAmount,
    //             'status' => 'awaiting_payment',
    //             'point' => $earnedPoints
    //         ]);

    //         foreach ($cartItems as $item) {
    //             $product = Product::lockForUpdate()->find($item->product_id);

    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Stock {$product->name} insufficient");
    //             }

    //             TransactionDetail::create([
    //                 'transaction_id' => $transaction->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $item->product->discount_price ?? $item->product->price
    //             ]);

    //             $product->decrement('stock', $item->quantity);
    //         }

    //         Cart::where('user_id', $user->id)->delete();

    //         return response()->json([
    //             'transaction_id' => $transaction->id,
    //             'order_id' => $orderId
    //         ], 201);
    //     });
    // }

    // --- USER ACTIONS ---
    // public function checkout(Request $request)
    // {
    //     $request->validate([
    //         'address_id' => 'required',
    //         'shipping_method' => 'required|in:free,biteship',
    //         'use_points' => 'nullable|integer|min:0',
    //         'cart_ids' => 'required|array',          // <-- PASTIKAN DIKIRIM DARI FRONTEND
    //         'cart_ids.*' => 'exists:carts,id',        // <-- PASTIKAN SEMUA ID VALID
    //         // ... validasi shipping_cost dll bisa ditaruh di sini
    //         'shipping_cost',
    //         'courier_company',
    //         'courier_type',
    //         'delivery_type',
    //         'order_id',
    //         'total_amount',
    //         'status',
    //         'point'
    //     ]);

    //     $user = $request->user();
    //     // $cartItems = Cart::with('product')->where('user_id', $user->id)->get();
    //     $cartItems = Cart::with('product')
    //         ->where('user_id', $user->id)
    //         ->whereIn('id', $request->cart_ids) // <-- KUNCI PENYELESAIAN
    //         ->get();

    //     // if ($cartItems->isEmpty()) {
    //     //     return response()->json(['message' => 'Cart is empty'], 400);
    //     // }

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['message' => 'No items selected for checkout'], 400);
    //     }

    //     return DB::transaction(function () use ($user, $cartItems, $request) {
    //         $totalAmount = $cartItems->sum('gross_amount');
    //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

    //         $earnedPoints = 0;
    //         if ($user->is_membership) {
    //             $earnedPoints = floor($totalAmount / 100000);
    //         }

    //         // 1. HITUNG DISKON POIN
    //         $pointsUsed = 0;
    //         $pointDiscountAmount = 0;
    //         if ($request->use_points > 0 && $user->is_membership) {
    //             $pointsUsed = min($request->use_points, $user->point);
    //             $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
    //             if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
    //         }

    //         // 2. HITUNG ONGKIR
    //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
    //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
    //         $totalShippingCost = $baseShippingRate * $totalQuantity;

    //         // 3. BUAT TRANSAKSI (Langsung status PENDING)
    //         $transaction = Transaction::create([
    //             'user_id' => $user->id,
    //             'address_id' => $request->address_id,
    //             'shipping_method' => $request->shipping_method,
    //             'shipping_cost' => $totalShippingCost,
    //             'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //             'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //             'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //             'delivery_date' => $request->delivery_date,
    //             'delivery_time' => $request->delivery_time,
    //             'order_id' => $orderId,
    //             'total_amount' => $totalAmount,
    //             'status' => 'pending', // LANGSUNG PENDING (Siap Bayar)
    //             'point' => $earnedPoints
    //         ]);

    //         // 4. BUAT DETAIL TRANSAKSI
    //         $xenditItems = [];
    //         foreach ($cartItems as $item) {
    //             $product = Product::lockForUpdate()->find($item->product_id);
    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Stock {$product->name} insufficient");
    //             }

    //             $price = $item->product->discount_price ?? $item->product->price;

    //             TransactionDetail::create([
    //                 'transaction_id' => $transaction->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $price
    //             ]);

    //             $product->decrement('stock', $item->quantity);

    //             // Siapkan item untuk Xendit
    //             $xenditItems[] = [
    //                 'name' => $product->name,
    //                 'quantity' => $item->quantity,
    //                 'price' => (int) $price,
    //                 'category' => 'PHYSICAL_PRODUCT'
    //             ];
    //         }

    //         // 5. HAPUS KERANJANG (HANYA KETIKA CHECKOUT BERHASIL)
    //         // Cart::where('user_id', $user->id)->delete();
    //         Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //         // 6. GENERATE XENDIT INVOICE DI SINI!
    //         $externalId = 'PAY-' . $orderId;

    //         if ($pointDiscountAmount > 0) {
    //             $xenditItems[] = [
    //                 'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
    //                 'quantity' => 1,
    //                 'price' => -(int) $pointDiscountAmount,
    //                 'category' => 'DISCOUNT'
    //             ];
    //         }

    //         if ($totalShippingCost > 0) {
    //             $xenditItems[] = [
    //                 'name' => 'Shipping Cost (' . $request->courier_company . ')',
    //                 'quantity' => (int) $totalQuantity,
    //                 'price' => (int) $baseShippingRate,
    //                 'category' => 'SHIPPING_FEE'
    //             ];
    //         }

    //         $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

    //         $invoiceRequest = new CreateInvoiceRequest([
    //             'external_id' => $externalId,
    //             'payer_email' => $user->email,
    //             'amount' => $finalAmount,
    //             'description' => 'Payment for Order ' . $orderId,
    //             'items' => $xenditItems,
    //             'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
    //             'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
    //         ]);

    //         $api = new InvoiceApi();
    //         $invoice = $api->createInvoice($invoiceRequest);

    //         Payment::create([
    //             'transaction_id' => $transaction->id,
    //             'external_id' => $externalId,
    //             'checkout_url' => $invoice['invoice_url'],
    //             'amount' => $totalAmount,
    //             'status' => 'pending'
    //         ]);

    //         // Kembalikan URL Xendit ke Frontend
    //         return response()->json([
    //             'checkout_url' => $invoice['invoice_url']
    //         ], 201);
    //     });
    // }

    // public function checkout(Request $request)
    // {
    //     $request->validate([
    //         'address_id' => 'required',
    //         'shipping_method' => 'required|in:free,biteship',
    //         'use_points' => 'nullable|integer|min:0',
    //         'cart_ids' => 'required|array',
    //         'cart_ids.*' => 'exists:carts,id',
    //         'shipping_cost' => 'nullable|numeric',
    //         'courier_company' => 'nullable|string',
    //         'courier_type' => 'nullable|string',
    //         'delivery_type' => 'nullable|string',
    //     ]);

    //     $user = $request->user();

    //     $cartItems = Cart::with('product')
    //         ->where('user_id', $user->id)
    //         ->whereIn('id', $request->cart_ids)
    //         ->get();

    //     if ($cartItems->isEmpty()) {
    //         return response()->json(['message' => 'No items selected for checkout'], 400);
    //     }

    //     // Mulai Transaksi Database
    //     return DB::transaction(function () use ($user, $cartItems, $request) {

    //         $totalAmount = $cartItems->sum('gross_amount');
    //         $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

    //         $earnedPoints = 0;
    //         if ($user->is_membership) {
    //             $earnedPoints = floor($totalAmount / 100000);
    //         }

    //         // 1. HITUNG DISKON POIN
    //         $pointsUsed = 0;
    //         $pointDiscountAmount = 0;
    //         if ($request->use_points > 0 && $user->is_membership) {
    //             $pointsUsed = min($request->use_points, $user->point);
    //             $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
    //             if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
    //         }

    //         // 2. HITUNG ONGKIR
    //         $totalQuantity = $cartItems->sum('quantity') ?: 1;
    //         $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
    //         $totalShippingCost = $baseShippingRate * $totalQuantity;

    //         // 3. BUAT TRANSAKSI
    //         $transaction = Transaction::create([
    //             'user_id' => $user->id,
    //             'address_id' => $request->address_id,
    //             'shipping_method' => $request->shipping_method,
    //             'shipping_cost' => $totalShippingCost,
    //             'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //             'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //             'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //             'order_id' => $orderId,
    //             'total_amount' => $totalAmount,
    //             'status' => 'pending',
    //             'point' => $earnedPoints
    //         ]);

    //         $xenditItems = [];

    //         // 4. LOOPING KERANJANG - CEK STOK & FIFO REDUCTION DENGAN LOCKING
    //         foreach ($cartItems as $item) {
    //             // [PENTING] LockForUpdate memastikan tidak ada race condition!
    //             $product = Product::lockForUpdate()->find($item->product_id);

    //             // Validasi Stok Utama
    //             if ($product->stock < $item->quantity) {
    //                 throw new \Exception("Stock for '{$product->name}' is insufficient. Available: {$product->stock}");
    //             }

    //             $price = $item->product->discount_price ?? $item->product->price;

    //             TransactionDetail::create([
    //                 'transaction_id' => $transaction->id,
    //                 'product_id' => $item->product_id,
    //                 'quantity' => $item->quantity,
    //                 'price' => $price
    //             ]);

    //             // ========================================================
    //             // LOGIKA FIFO: PENGURANGAN STOK BERDASARKAN BATCH TERLAMA
    //             // ========================================================
    //             $remainingQuantityToDeduct = $item->quantity;

    //             // Ambil semua batch stok yang masih ada isinya, urutkan dari yang PALING LAMA (created_at ASC)
    //             $activeBatches = \App\Models\ProductStock::where('product_id', $product->id)
    //                 ->where('quantity', '>', 0)
    //                 ->orderBy('created_at', 'asc')
    //                 // lockForUpdate() agar batch tidak dimodifikasi proses lain
    //                 ->lockForUpdate()
    //                 ->get();

    //             foreach ($activeBatches as $batch) {
    //                 if ($remainingQuantityToDeduct <= 0) break; // Jika sudah terpenuhi, hentikan loop

    //                 if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                     // Batch ini cukup untuk menutupi sisa pesanan
    //                     $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                     $remainingQuantityToDeduct = 0;
    //                 } else {
    //                     // Batch ini tidak cukup, kurangi semua isinya, lalu sisa pesanan dicarikan di batch berikutnya
    //                     $remainingQuantityToDeduct -= $batch->quantity;
    //                     $batch->update(['quantity' => 0]);
    //                 }
    //             }

    //             // Jika setelah melooping semua batch ternyata masih ada sisa (Data tidak sinkron antara master dan batch)
    //             if ($remainingQuantityToDeduct > 0) {
    //                 throw new \Exception("System error: Stock batch mismatch for '{$product->name}'. Please contact admin.");
    //             }

    //             // Kurangi Total Stok di tabel Master Products
    //             $product->decrement('stock', $item->quantity);
    //             // ========================================================

    //             // Siapkan item untuk Xendit
    //             $xenditItems[] = [
    //                 'name' => $product->name,
    //                 'quantity' => $item->quantity,
    //                 'price' => (int) $price,
    //                 'category' => 'PHYSICAL_PRODUCT'
    //             ];
    //         }

    //         // 5. HAPUS KERANJANG
    //         Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //         // 6. GENERATE XENDIT INVOICE
    //         $externalId = 'PAY-' . $orderId;

    //         if ($pointDiscountAmount > 0) {
    //             $xenditItems[] = [
    //                 'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
    //                 'quantity' => 1,
    //                 'price' => -(int) $pointDiscountAmount,
    //                 'category' => 'DISCOUNT'
    //             ];
    //         }

    //         if ($totalShippingCost > 0) {
    //             $xenditItems[] = [
    //                 'name' => 'Shipping Cost (' . $request->courier_company . ')',
    //                 'quantity' => (int) $totalQuantity,
    //                 'price' => (int) $baseShippingRate,
    //                 'category' => 'SHIPPING_FEE'
    //             ];
    //         }

    //         $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

    //         $invoiceRequest = new CreateInvoiceRequest([
    //             'external_id' => $externalId,
    //             'payer_email' => $user->email,
    //             'amount' => $finalAmount,
    //             'description' => 'Payment for Order ' . $orderId,
    //             'items' => $xenditItems,
    //             'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
    //             'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
    //         ]);

    //         $api = new InvoiceApi();
    //         $invoice = $api->createInvoice($invoiceRequest);

    //         Payment::create([
    //             'transaction_id' => $transaction->id,
    //             'external_id' => $externalId,
    //             'checkout_url' => $invoice['invoice_url'],
    //             'amount' => $totalAmount,
    //             'status' => 'pending'
    //         ]);

    //         return response()->json([
    //             'checkout_url' => $invoice['invoice_url']
    //         ], 201);
    //     });
    // }

    // --- USER ACTIONS ---
    public function checkout(Request $request)
    {
        $request->validate([
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'use_points' => 'nullable|integer|min:0',
            'cart_ids' => 'required|array',
            'cart_ids.*' => 'exists:carts,id',
            'shipping_cost' => 'nullable|numeric',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'delivery_type' => 'nullable|string',
        ]);

        $user = $request->user();
        $cartItems = Cart::with('product')
            ->where('user_id', $user->id)
            ->whereIn('id', $request->cart_ids)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'No items selected for checkout'], 400);
        }

        // [PENTING] Membungkus seluruh checkout dengan DB Transaction (Mencegah Race Condition)
        return DB::transaction(function () use ($user, $cartItems, $request) {
            $totalAmount = $cartItems->sum('gross_amount');
            $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $earnedPoints = 0;
            if ($user->is_membership) {
                $earnedPoints = floor($totalAmount / 100000);
            }

            // 1. HITUNG DISKON POIN
            $pointsUsed = 0;
            $pointDiscountAmount = 0;
            if ($request->use_points > 0 && $user->is_membership) {
                $pointsUsed = min($request->use_points, $user->point);
                $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
                if ($pointsUsed > 0) $user->decrement('point', $pointsUsed);
            }

            // 2. HITUNG ONGKIR
            $totalQuantity = $cartItems->sum('quantity') ?: 1;
            $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
            $totalShippingCost = $baseShippingRate * $totalQuantity;

            // 3. BUAT TRANSAKSI (Langsung status PENDING)
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method,
                'shipping_cost' => $totalShippingCost,
                'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
                'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
                'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'point' => $earnedPoints,
                'points_used' => $pointsUsed // SIMPAN POIN YANG DIPAKAI
            ]);

            $xenditItems = [];
            foreach ($cartItems as $item) {

                // [PENTING] Kunci baris produk (Anti Race Condition saat stok menipis)
                $product = Product::lockForUpdate()->find($item->product_id);
                if ($product->stock < $item->quantity) {
                    throw new \Exception("Stock {$product->name} insufficient");
                }

                $price = $item->product->discount_price ?? $item->product->price;

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $price
                ]);

                // ========================================================
                // PENGURANGAN STOK FIFO DARI TABEL BATCH
                // ========================================================
                $remainingQuantityToDeduct = $item->quantity;

                // $activeBatches = ProductStock::where('product_id', $product->id)
                //     ->where('quantity', '>', 0)
                //     ->orderBy('created_at', 'asc') // FIFO: Ambil yang tertua
                //     ->lockForUpdate() // Kunci batch ini
                //     ->get();

                // foreach ($activeBatches as $batch) {
                //     if ($remainingQuantityToDeduct <= 0) break;

                //     if ($batch->quantity >= $remainingQuantityToDeduct) {
                //         $batch->decrement('quantity', $remainingQuantityToDeduct);
                //         $remainingQuantityToDeduct = 0;
                //     } else {
                //         $remainingQuantityToDeduct -= $batch->quantity;
                //         $batch->update(['quantity' => 0]);
                //     }
                // }

                // // if ($remainingQuantityToDeduct > 0) {
                // //     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
                // // }

                // // // Kurangi Total Stok Master
                // // $product->decrement('stock', $item->quantity);

                // // [PERBAIKAN] SELF-HEALING DATA USANG
                // // Jika setelah looping batch ternyata masih ada sisa yang belum terpotong
                // // Ini terjadi pada produk LAMA yang diinput sebelum fitur FIFO dibuat.
                // if ($remainingQuantityToDeduct > 0) {
                //     \Illuminate\Support\Facades\Log::warning("Auto-healing stock mismatch for product ID: {$product->id}. Creating missing legacy batch.");

                //     // Buat batch fiktif (System Adjustment) on-the-fly untuk menyeimbangkan neraca
                //     ProductStock::create([
                //         'product_id' => $product->id,
                //         'batch_code' => 'SYS-ADJ-' . now()->format('YmdHis') . '-' . strtoupper(\Illuminate\Support\Str::random(4)),
                //         'quantity' => 0, // Langsung 0 karena dipakai habis untuk pesanan ini
                //         'initial_quantity' => $remainingQuantityToDeduct
                //     ]);

                //     $remainingQuantityToDeduct = 0; // Anggap sudah berhasil dipotong
                // }

                // // Kurangi Total Stok Master
                // $product->decrement('stock', $item->quantity);

                // 1. CEK STOK SILUMAN (LEGACY STOCK) TERLEBIH DAHULU!
                // Menghitung selisih antara master stok dengan total batch fisik
                // Stok siluman adalah stok paling tua (sebelum fitur batch ada), wajib dihabiskan pertama.
                $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
                $legacyStock = $product->stock - $totalBatchQuantity;

                if ($legacyStock > 0) {
                    // Ambil dari stok lama sebanyak yang dibutuhkan (tidak boleh lebih dari sisa stok lama)
                    $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);

                    // Buat riwayat pemotongan fiktif agar terekam di database
                    ProductStock::create([
                        'product_id' => $product->id,
                        'batch_code' => 'SYS-LEGACY-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                        'quantity' => 0, // Langsung 0 karena dipakai habis untuk pesanan ini
                        'initial_quantity' => $takeFromLegacy
                    ]);

                    $remainingQuantityToDeduct -= $takeFromLegacy;
                }

                // 2. JIKA MASIH ADA SISA PESANAN, BARU POTONG DARI BATCH FISIK
                if ($remainingQuantityToDeduct > 0) {
                    $activeBatches = ProductStock::where('product_id', $product->id)
                        ->where('quantity', '>', 0)
                        ->orderBy('created_at', 'asc') // FIFO: Ambil yang tertua
                        ->lockForUpdate() // Kunci batch ini untuk cegah Race Condition
                        ->get();

                    foreach ($activeBatches as $batch) {
                        if ($remainingQuantityToDeduct <= 0) break;

                        if ($batch->quantity >= $remainingQuantityToDeduct) {
                            $batch->decrement('quantity', $remainingQuantityToDeduct);
                            $remainingQuantityToDeduct = 0;
                        } else {
                            $remainingQuantityToDeduct -= $batch->quantity;
                            $batch->update(['quantity' => 0]);
                        }
                    }
                }

                // Jika ternyata masih ada sisa setelah looping (Seharusnya tidak mungkin terjadi karena sudah divalidasi $product->stock di awal)
                if ($remainingQuantityToDeduct > 0) {
                    throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
                }

                // Kurangi Total Stok Master
                $product->decrement('stock', $item->quantity);

                // ========================================================

                $xenditItems[] = [
                    'name' => $product->name,
                    'quantity' => $item->quantity,
                    'price' => (int) $price,
                    'category' => 'PHYSICAL_PRODUCT'
                ];
            }

            // 5. HAPUS KERANJANG
            Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

            // 6. GENERATE XENDIT INVOICE
            $externalId = 'PAY-' . $orderId;

            if ($pointDiscountAmount > 0) {
                $xenditItems[] = [
                    'name' => 'Loyalty Point Discount (' . $pointsUsed . ' Pts)',
                    'quantity' => 1,
                    'price' => -(int) $pointDiscountAmount,
                    'category' => 'DISCOUNT'
                ];
            }

            if ($totalShippingCost > 0) {
                $xenditItems[] = [
                    'name' => 'Shipping Cost (' . $request->courier_company . ')',
                    'quantity' => (int) $totalQuantity,
                    'price' => (int) $baseShippingRate,
                    'category' => 'SHIPPING_FEE'
                ];
            }

            $finalAmount = (int) $totalAmount + $totalShippingCost - $pointDiscountAmount;

            $invoiceRequest = new CreateInvoiceRequest([
                'external_id' => $externalId,
                'payer_email' => $user->email,
                'amount' => $finalAmount,
                'description' => 'Payment for Order ' . $orderId,
                'items' => $xenditItems,
                'success_redirect_url' => config('app.frontend_url') . '/payment-success?external_id=' . $externalId . '&order_id=' . $orderId,
                'failure_redirect_url' => config('app.frontend_url') . '/payment-failed',
            ]);

            $api = new InvoiceApi();
            $invoice = $api->createInvoice($invoiceRequest);

            Payment::create([
                'transaction_id' => $transaction->id,
                'external_id' => $externalId,
                'checkout_url' => $invoice['invoice_url'],
                'amount' => $totalAmount,
                'status' => 'pending'
            ]);

            Cache::tags(['catalog'])->flush();

            return response()->json([
                'checkout_url' => $invoice['invoice_url']
            ], 201);
        });
    }

    public function index(Request $request)
    {
        // Eager load 'payment' untuk mendapatkan checkout_url
        $transactions = Transaction::with(['details.product', 'payment', 'address'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();
        return response()->json($transactions);
    }

    // Melihat semua transaksi (Sisi Admin)
    // public function allTransactions()
    // {
    //     $transactions = Transaction::with(['user', 'details.product'])
    //         ->latest()
    //         ->get();
    //     return response()->json($transactions);
    // }

    public function allTransactions()
    {
        // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
        $transactions = Transaction::with(['user', 'details.product', 'address'])
            ->latest()
            ->get();

        return response()->json($transactions);
    }

    // public function cancelOrder(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     if (!in_array($transaction->status, ['awaiting_payment', 'pending'])) {
    //         return response()->json(['message' => 'Cannot cancel this order.'], 400);
    //     }

    //     // [BARU] Logika membatalkan pesanan di server Biteship
    //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $response = \Illuminate\Support\Facades\Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key')
    //             ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

    //             $biteshipData = $response->json();

    //             // Deteksi jika Biteship menolak pembatalan (misalnya kurir sudah dalam perjalanan / "picking_up")
    //             if (isset($biteshipData['success']) && $biteshipData['success'] === false) {
    //                 \Illuminate\Support\Facades\Log::warning('Biteship Cancel Error: ' . json_encode($biteshipData));

    //                 // Anda bisa memblokir pembatalan lokal jika kurir sudah terlanjur jalan
    //                 return response()->json([
    //                     'message' => 'Cannot cancel: Courier is already processing this order. (' . ($biteshipData['error'] ?? 'Logistics error') . ')'
    //                 ], 400);
    //             }
    //         } catch (\Exception $e) {
    //             \Illuminate\Support\Facades\Log::error('Biteship Cancel Exception: ' . $e->getMessage());
    //             return response()->json(['message' => 'Failed to connect to logistics provider.'], 500);
    //         }
    //     }

    //     // Update status database lokal
    //     $transaction->update(['status' => 'cancelled']);
    //     if ($transaction->payment) {
    //         $transaction->payment->update(['status' => 'EXPIRED']); // Update status payment lokal
    //     }

    //     // Kembalikan stok
    //     foreach ($transaction->details as $detail) {
    //         $detail->product->increment('stock', $detail->quantity);
    //     }

    //     return response()->json(['message' => 'Order cancelled successfully']);
    // }

    // public function cancelOrder(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     // [PERBAIKAN 1] Izinkan pembatalan untuk status processing
    //     if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
    //         return response()->json(['message' => 'Cannot cancel this order.'], 400);
    //     }

    //     // Jika statusnya processing (sudah dibayar), lakukan pre-check ke Biteship
    //     if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $res = \Illuminate\Support\Facades\Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key')
    //             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

    //             if ($res->successful()) {
    //                 $data = $res->json();
    //                 $biteshipStatus = strtolower($data['status'] ?? '');

    //                 // Jika barang sudah diambil kurir, TOLAK pembatalan
    //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
    //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
    //                     return response()->json([
    //                         'message' => 'Cannot cancel: The package is already being processed by the courier (Status: ' . strtoupper($biteshipStatus) . ').'
    //                     ], 400);
    //                 }

    //                 // Jika masih aman, batalkan order di Biteship
    //                 \Illuminate\Support\Facades\Http::withHeaders([
    //                     'Authorization' => config('services.biteship.api_key')
    //                 ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
    //             }
    //         } catch (\Exception $e) {
    //             \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Cancel Error: ' . $e->getMessage());
    //             return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
    //         }

    //         // [PENTING] Lakukan proses Refund via Xendit karena statusnya processing (sudah bayar)
    //         try {
    //             $transaction->load('payment');
    //             if ($transaction->payment && $transaction->payment->external_id) {
    //                 $invoiceApi = new InvoiceApi();
    //                 $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //                 if (!empty($invoices) && count($invoices) > 0) {
    //                     $xenditInvoiceId = $invoices[0]['id'];
    //                     $refundApi = new RefundApi();

    //                     $refundRequest = new CreateRefund([
    //                         'invoice_id' => $xenditInvoiceId,
    //                         'reason' => 'REQUESTED_BY_CUSTOMER',
    //                         'amount' => (int) $transaction->total_amount,
    //                         'metadata' => ['order_id' => $transaction->order_id]
    //                     ]);

    //                     $refundApi->createRefund(null, null, $refundRequest);
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             \Illuminate\Support\Facades\Log::error('Auto-Refund on Cancel Error: ' . $e->getMessage());
    //             // Jika auto-refund gagal, kita ubah statusnya agar admin memprosesnya secara manual
    //             $transaction->update(['status' => 'refund_manual_required']);

    //             // Kembalikan stok
    //             foreach ($transaction->details as $detail) {
    //                 $detail->product->increment('stock', $detail->quantity);
    //             }

    //             return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
    //         }
    //     }

    //     // Update status database lokal (jika bukan processing, atau refund berhasil)
    //     if ($transaction->status !== 'refund_manual_required') {
    //         $transaction->update(['status' => 'cancelled']);
    //     }

    //     if ($transaction->payment && $transaction->status !== 'refund_manual_required') {
    //         $transaction->payment->update(['status' => 'EXPIRED']); // Atau REFUNDED jika dari processing
    //     }

    //     // Kembalikan stok
    //     foreach ($transaction->details as $detail) {
    //         $detail->product->increment('stock', $detail->quantity);
    //     }

    //     return response()->json(['message' => 'Order cancelled successfully']);
    // }

    public function cancelOrder(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }

        // PRE-CHECK BITESHIP (Berjalan di luar transaksi database agar tidak memberatkan server)
        if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $res = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        return response()->json([
                            'message' => 'Cannot cancel: The package is already being processed by the courier.'
                        ], 400);
                    }

                    \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => config('services.biteship.api_key')
                    ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
                }
            } catch (\Exception $e) {
                return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
            }

            // AUTO-REFUND XENDIT
            try {
                $transaction->load('payment');
                if ($transaction->payment && $transaction->payment->external_id) {
                    $invoiceApi = new InvoiceApi();
                    $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

                    if (!empty($invoices) && count($invoices) > 0) {
                        $xenditInvoiceId = $invoices[0]['id'];
                        $refundApi = new RefundApi();

                        $refundRequest = new CreateRefund([
                            'invoice_id' => $xenditInvoiceId,
                            'reason' => 'REQUESTED_BY_CUSTOMER',
                            'amount' => (int) $transaction->total_amount,
                            'metadata' => ['order_id' => $transaction->order_id]
                        ]);

                        $refundApi->createRefund(null, null, $refundRequest);
                    }
                }
            } catch (\Exception $e) {
                // JIKA REFUND GAGAL (TAPI KURIR SUDAH DIBATALKAN), LEMPAR KE REFUND MANUAL TAPI KEMBALIKAN STOKNYA
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
            }
        }

        // [PENTING] Bungkus pembatalan status dan pengembalian stok dalam DB Transaction
        DB::transaction(function () use ($transaction) {
            // Re-fetch dan Lock untuk mencegah error paralel
            $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

            if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
                $lockedTransaction->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled' // [PERBAIKAN] Sinkronisasi status pengiriman
                ]);

                // [PERBAIKAN] KEMBALIKAN POIN YANG HANGUS
                if ($lockedTransaction->points_used > 0) {
                    $lockedTransaction->user->increment('point', $lockedTransaction->points_used);
                }
            }

            if ($lockedTransaction->payment && $lockedTransaction->status !== 'refund_manual_required') {
                $lockedTransaction->payment->update(['status' => 'EXPIRED']);
            }

            // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
            foreach ($lockedTransaction->details as $detail) {
                $this->restoreProductStock($detail->product_id, $detail->quantity);
            }
        });

        Cache::tags(['catalog'])->flush();

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }

        $transaction->update(['status' => 'completed']);

        // [PERBAIKAN] Cek syarat membership setelah admin komplit manual
        $this->checkAndAssignMembership($transaction->user);

        return response()->json(['message' => 'Order completed!']);
    }

    // public function requestRefund(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     // Refund bisa diajukan saat status ini
    //     if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
    //         return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
    //     }

    //     $transaction->update(['status' => 'refund_requested']);
    //     return response()->json(['message' => 'Refund requested. Waiting for admin approval.']);
    // }

    public function requestRefund(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        // Validasi: Refund hanya bisa diajukan saat pesanan selesai atau gagal kirim
        if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        // [BARU] Validasi input text dan file bukti (gambar atau video)
        $request->validate([
            'reason' => 'required|string|max:1000',
            'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240' // Max 10MB
        ]);

        try {
            // [BARU] Upload file ke AWS S3
            $file = $request->file('proof_file');
            $path = $file->store('refund_proofs', [
                'disk' => 's3',
                'visibility' => 'public'
            ]);
            $proofUrl = \Illuminate\Support\Facades\Storage::disk('s3')->url($path);

            // Update transaksi
            $transaction->update([
                'status' => 'refund_requested',
                'refund_reason' => $request->reason,
                'refund_proof_url' => $proofUrl
            ]);

            return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to upload refund proof: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
        }
    }

    // User klik "Refund Now" setelah disetujui admin
    // public function processRefundUser(Request $request, $id)
    // {
    //     // 1. Validasi Transaksi Lokal
    //     $transaction = Transaction::with('payment')
    //         ->where('user_id', $request->user()->id)
    //         ->findOrFail($id);

    //     if ($transaction->status !== 'refund_approved') {
    //         return response()->json(['message' => 'Refund not approved yet.'], 400);
    //     }

    //     if (!$transaction->payment) {
    //         return response()->json(['message' => 'Payment data not found.'], 404);
    //     }

    //     // --- PRE-CHECK: Validasi Status Kurir Biteship SEBELUM Refund ---
    //     $shouldCancelCourier = false;
    //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $res = \Illuminate\Support\Facades\Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key')
    //             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

    //             if ($res->successful()) {
    //                 $data = $res->json();
    //                 $biteshipStatus = strtolower($data['status'] ?? '');

    //                 // Daftar status di mana pesanan SUDAH TIDAK BISA dibatalkan
    //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected'];

    //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
    //                     // CEGAH REFUND JIKA KURIR SUDAH JALAN/SELESAI
    //                     return response()->json([
    //                         'message' => 'Cannot process refund: The package is already in transit or delivered (Status: ' . strtoupper($biteshipStatus) . '). Please return the item first.'
    //                     ], 400);
    //                 }

    //                 // Jika status masih aman (placed, allocated, picking_up), tandai untuk dibatalkan nanti
    //                 if (!in_array($biteshipStatus, ['cancelled'])) {
    //                     $shouldCancelCourier = true;
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             // Jika API Biteship down, kita harus berhati-hati.
    //             // Untuk keamanan, batalkan proses refund jika kita tidak bisa memastikan status kurir.
    //             \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
    //             return response()->json([
    //                 'message' => 'Failed to verify logistics status with Biteship. Please try again later.'
    //             ], 500);
    //         }
    //     }

    //     // --- EKSEKUSI REFUND KE XENDIT ---
    //     try {
    //         // STEP A: Cari Invoice ID
    //         $invoiceApi = new InvoiceApi();
    //         $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //         if (empty($invoices) || count($invoices) === 0) {
    //             throw new \Exception("Invoice not found in Xendit.");
    //         }

    //         $xenditInvoiceId = $invoices[0]['id'];

    //         // STEP B: Coba Refund via API
    //         $refundApi = new RefundApi();

    //         $refundRequest = new CreateRefund([
    //             'invoice_id' => $xenditInvoiceId,
    //             'reason' => 'REQUESTED_BY_CUSTOMER',
    //             'amount' => (int) $transaction->total_amount,
    //             'metadata' => [
    //                 'order_id' => $transaction->order_id
    //             ]
    //         ]);

    //         $result = $refundApi->createRefund(null, null, $refundRequest);

    //         // Jika Xendit sukses, update database
    //         DB::transaction(function () use ($transaction) {
    //             $transaction->update(['status' => 'refunded']);
    //             if ($transaction->payment) {
    //                 $transaction->payment->update(['status' => 'REFUNDED']);
    //             }
    //         });

    //         // STEP C: Batalkan Kurir Biteship (Karena kita sudah pastikan statusnya aman untuk dicancel)
    //         if ($shouldCancelCourier) {
    //             \Illuminate\Support\Facades\Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key')
    //             ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
    //         }

    //         return response()->json([
    //             'message' => 'Refund processed successfully. Funds returned automatically.',
    //             'type' => 'automatic'
    //         ]);
    //     } catch (XenditSdkException $e) {
    //         // --- Handling Khusus Jika Channel Tidak Support Refund ---
    //         $errorMessage = $e->getMessage();

    //         if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
    //             // Update status menjadi 'refund_manual_required'
    //             $transaction->update(['status' => 'refund_manual_required']);

    //             // Tetap batalkan kurir Biteship karena pesanan ini di-hold untuk refund manual
    //             if ($shouldCancelCourier) {
    //                 \Illuminate\Support\Facades\Http::withHeaders([
    //                     'Authorization' => config('services.biteship.api_key')
    //                 ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);
    //             }

    //             return response()->json([
    //                 'message' => 'Automatic refund not supported for this payment method. Status updated to Manual Check.',
    //                 'code' => 'MANUAL_REFUND_NEEDED'
    //             ], 200);
    //         }

    //         \Illuminate\Support\Facades\Log::error('Xendit Refund Error: ' . $errorMessage);
    //         return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('System Refund Error: ' . $e->getMessage());
    //         return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
    //     }
    // }

    // public function processRefundUser(Request $request, $id)
    // {
    //     $transaction = Transaction::with('payment')
    //         ->where('user_id', $request->user()->id)
    //         ->findOrFail($id);

    //     if ($transaction->status !== 'refund_approved') {
    //         return response()->json(['message' => 'Refund not approved yet.'], 400);
    //     }

    //     if (!$transaction->payment) {
    //         return response()->json(['message' => 'Payment data not found.'], 404);
    //     }

    //     // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR (DILAKUKAN PERTAMA) ---
    //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $res = \Illuminate\Support\Facades\Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key')
    //             ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

    //             if ($res->successful()) {
    //                 $data = $res->json();
    //                 $biteshipStatus = strtolower($data['status'] ?? '');

    //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

    //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
    //                     return response()->json([
    //                         'message' => 'Cannot process refund: The package is already in transit or has issues (Status: ' . strtoupper($biteshipStatus) . '). Please contact logistics.'
    //                     ], 400);
    //                 }

    //                 // JIKA AMAN, BATALKAN KURIR SEKARANG JUGA
    //                 if (!in_array($biteshipStatus, ['cancelled'])) {
    //                     $cancelRes = \Illuminate\Support\Facades\Http::withHeaders([
    //                         'Authorization' => config('services.biteship.api_key')
    //                     ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

    //                     $cancelData = $cancelRes->json();
    //                     if (isset($cancelData['success']) && $cancelData['success'] === false) {
    //                         return response()->json([
    //                             'message' => 'Failed to cancel courier. Refund aborted to prevent loss.'
    //                         ], 400);
    //                     }
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
    //             return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
    //         }
    //     }

    //     // --- JIKA KURIR BERHASIL DIBATALKAN, BARU KEMBALIKAN UANGNYA ---
    //     // try {
    //     //     $invoiceApi = new InvoiceApi();
    //     //     $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //     //     if (empty($invoices) || count($invoices) === 0) {
    //     //         throw new \Exception("Invoice not found in Xendit.");
    //     //     }

    //     //     $xenditInvoiceId = $invoices[0]['id'];
    //     //     $refundApi = new RefundApi();

    //     //     $refundRequest = new CreateRefund([
    //     //         'invoice_id' => $xenditInvoiceId,
    //     //         'reason' => 'REQUESTED_BY_CUSTOMER',
    //     //         'amount' => (int) $transaction->total_amount,
    //     //         'metadata' => ['order_id' => $transaction->order_id]
    //     //     ]);

    //     //     $result = $refundApi->createRefund(null, null, $refundRequest);

    //     //     // Jika Xendit sukses, update database lokal
    //     //     DB::transaction(function () use ($transaction) {
    //     //         $transaction->update(['status' => 'refunded']);
    //     //         if ($transaction->payment) {
    //     //             $transaction->payment->update(['status' => 'REFUNDED']);
    //     //         }

    //     //         // Pastikan user adalah member dan transaksi ini sebelumnya menghasilkan poin
    //     //         if ($transaction->point > 0 && $transaction->user->is_membership) {
    //     //             // Cegah poin user menjadi minus jika dia sudah terlanjur memakainya
    //     //             $currentPoints = $transaction->user->point;
    //     //             $pointsToDeduct = min($currentPoints, $transaction->point);

    //     //             if ($pointsToDeduct > 0) {
    //     //                 $transaction->user->decrement('point', $pointsToDeduct);
    //     //             }

    //     //             // Nolkan poin di transaksi agar tidak ditarik ganda di masa depan
    //     //             $transaction->update(['point' => 0]);
    //     //         }
    //     //     });

    //     //     return response()->json([
    //     //         'message' => 'Refund processed successfully. Funds returned automatically.',
    //     //         'type' => 'automatic'
    //     //     ]);
    //     // } catch (XenditSdkException $e) {
    //     //     $errorMessage = $e->getMessage();

    //     //     if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
    //     //         // Kurir sudah dibatalkan di atas, jadi aman untuk mengubah ke manual_required
    //     //         $transaction->update(['status' => 'refund_manual_required']);

    //     //         return response()->json([
    //     //             'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
    //     //             'code' => 'MANUAL_REFUND_NEEDED'
    //     //         ], 200);
    //     //     }

    //     //     \Illuminate\Support\Facades\Log::error('Xendit Refund Error: ' . $errorMessage);
    //     //     return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
    //     // } catch (\Exception $e) {
    //     //     \Illuminate\Support\Facades\Log::error('System Refund Error: ' . $e->getMessage());
    //     //     return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
    //     // }

    //     // --- EKSEKUSI REFUND KE XENDIT ---
    //     try {
    //         $invoiceApi = new InvoiceApi();
    //         $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //         if (empty($invoices) || count($invoices) === 0) {
    //             throw new \Exception("Invoice not found in Xendit.");
    //         }

    //         $xenditInvoiceId = $invoices[0]['id'];
    //         $refundApi = new RefundApi();

    //         $refundRequest = new CreateRefund([
    //             'invoice_id' => $xenditInvoiceId,
    //             'reason' => 'REQUESTED_BY_CUSTOMER',
    //             'amount' => (int) $transaction->total_amount,
    //             'metadata' => ['order_id' => $transaction->order_id]
    //         ]);

    //         $refundApi->createRefund(null, null, $refundRequest);

    //         // [PENTING] Jika Xendit sukses, update DB & Kembalikan Stok FIFO dalam 1 Transaksi
    //         DB::transaction(function () use ($transaction) {
    //             $transaction->update(['status' => 'refunded']);
    //             if ($transaction->payment) {
    //                 $transaction->payment->update(['status' => 'REFUNDED']);
    //             }

    //             if ($transaction->point > 0 && $transaction->user->is_membership) {
    //                 $currentPoints = $transaction->user->point;
    //                 $pointsToDeduct = min($currentPoints, $transaction->point);
    //                 if ($pointsToDeduct > 0) {
    //                     $transaction->user->decrement('point', $pointsToDeduct);
    //                 }
    //                 $transaction->update(['point' => 0]);
    //             }

    //             // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore saat sukses direfund
    //             foreach ($transaction->details as $detail) {
    //                 $this->restoreProductStock($detail->product_id, $detail->quantity);
    //             }
    //         });

    //         Cache::tags(['catalog'])->flush();

    //         return response()->json([
    //             'message' => 'Refund processed successfully. Funds returned automatically.',
    //             'type' => 'automatic'
    //         ]);
    //     } catch (XenditSdkException $e) {
    //         $errorMessage = $e->getMessage();

    //         if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
    //             // [PENTING] Karena manual refund, stok juga kita kembalikan sekarang karena barangnya batal terkirim
    //             DB::transaction(function () use ($transaction) {
    //                 $transaction->update(['status' => 'refund_manual_required']);

    //                 foreach ($transaction->details as $detail) {
    //                     $this->restoreProductStock($detail->product_id, $detail->quantity);
    //                 }
    //             });

    //             Cache::tags(['catalog'])->flush();

    //             return response()->json([
    //                 'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
    //                 'code' => 'MANUAL_REFUND_NEEDED'
    //             ], 200);
    //         }

    //         return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
    //     } catch (\Exception $e) {
    //         return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
    //     }
    // }

    public function processRefundUser(Request $request, $id)
    {
        // 1. Ambil data transaksi (Tanpa Lock terlebih dahulu)
        $transaction = Transaction::with('payment')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // =========================================================================
        // [PERBAIKAN] ATOMIC STATE TRANSITION (Pencegah Double Refund)
        // Kita paksa ubah statusnya di database SEBELUM memanggil API Xendit.
        // Jika ada 2 request masuk bersamaan, request kedua akan menghasilkan $locked = 0 (Gagal)
        // =========================================================================
        $locked = Transaction::where('id', $id)
            ->where('status', 'refund_approved')
            ->update(['status' => 'refund_processing']); // Status sementara

        if (!$locked) {
            return response()->json(['message' => 'Refund is already being processed or not valid.'], 400);
        }

        if (!$transaction->payment) {
            // Rollback status karena gagal
            $transaction->update(['status' => 'refund_approved']);
            return response()->json(['message' => 'Payment data not found.'], 404);
        }

        // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR ---
        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $res = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        // Rollback status karena kurir sudah jalan
                        $transaction->update(['status' => 'refund_approved']);
                        return response()->json([
                            'message' => 'Cannot process refund: The package is already in transit or has issues. Please contact logistics.'
                        ], 400);
                    }

                    // JIKA AMAN, BATALKAN KURIR
                    if (!in_array($biteshipStatus, ['cancelled'])) {
                        $cancelRes = \Illuminate\Support\Facades\Http::withHeaders([
                            'Authorization' => config('services.biteship.api_key')
                        ])->delete("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                        $cancelData = $cancelRes->json();
                        if (isset($cancelData['success']) && $cancelData['success'] === false) {
                            $transaction->update(['status' => 'refund_approved']); // Rollback
                            return response()->json([
                                'message' => 'Failed to cancel courier. Refund aborted to prevent loss.'
                            ], 400);
                        }
                    }
                }
            } catch (\Exception $e) {
                $transaction->update(['status' => 'refund_approved']); // Rollback
                \Illuminate\Support\Facades\Log::error('Biteship Pre-Check Error: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
            }
        }

        // --- EKSEKUSI REFUND KE XENDIT ---
        try {
            $invoiceApi = new InvoiceApi();
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception("Invoice not found in Xendit.");
            }

            $xenditInvoiceId = $invoices[0]['id'];
            $refundApi = new RefundApi();

            $refundRequest = new CreateRefund([
                'invoice_id' => $xenditInvoiceId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => ['order_id' => $transaction->order_id]
            ]);

            $refundApi->createRefund(null, null, $refundRequest);

            // Jika Xendit sukses, update ke status Akhir (Refunded)
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }

                // Pengembalian poin yang dipakai ada di Fix Bencana 2 di bawah

                foreach ($transaction->details as $detail) {
                    $this->restoreProductStock($detail->product_id, $detail->quantity);
                }
            });

            Cache::tags(['catalog'])->flush();

            return response()->json([
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic'
            ]);
        } catch (XenditSdkException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                Cache::tags(['catalog'])->flush();
                return response()->json([
                    'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
                    'code' => 'MANUAL_REFUND_NEEDED'
                ], 200);
            }

            $transaction->update(['status' => 'refund_approved']); // Rollback
            return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
        } catch (\Exception $e) {
            $transaction->update(['status' => 'refund_approved']); // Rollback
            return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
        }
    }

    public function approveRefund($id)
    {
        $transaction = Transaction::findOrFail($id);
        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_approved']);
        return response()->json(['message' => 'Refund request approved.']);
    }

    public function rejectRefund($id)
    {
        $transaction = Transaction::findOrFail($id);
        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_rejected']);
        return response()->json(['message' => 'Refund request rejected.']);
    }

    // Show single transaction
    public function show($id)
    {
        return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
    }

    public function adminShow($id)
    {
        // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
        $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    // public function salesReport(Request $request)
    // {
    //     $month = $request->query('month'); // Format: 1-12
    //     $year = $request->query('year');   // Format: YYYY
    //     $search = $request->query('search'); // Pencarian nama produk
    //     $perPage = $request->query('per_page', 10);

    //     $query = TransactionDetail::query()
    //         ->select(
    //             'products.id',
    //             'products.code',
    //             'products.name',
    //             'products.image',
    //             'categories.name as category_name',
    //             DB::raw('SUM(transaction_details.quantity) as total_sold'),
    //             DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
    //         )
    //         ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
    //         ->join('products', 'products.id', '=', 'transaction_details.product_id')
    //         ->join('categories', 'categories.id', '=', 'products.category_id')
    //         ->whereIn('transactions.status', ['completed', 'refund_rejected']);

    //     // Filter Bulan & Tahun
    //     if ($month && $year) {
    //         $query->whereMonth('transactions.created_at', $month)
    //             ->whereYear('transactions.created_at', $year);
    //     } elseif ($year) {
    //         $query->whereYear('transactions.created_at', $year);
    //     }

    //     // Filter Pencarian (Nama Produk atau Kode)
    //     if ($search) {
    //         $query->where(function ($q) use ($search) {
    //             $q->where('products.name', 'like', "%{$search}%")
    //                 ->orWhere('products.code', 'like', "%{$search}%");
    //         });
    //     }

    //     // Grouping & Ordering
    //     $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
    //         ->orderByDesc('total_revenue') // Urutkan dari omzet tertinggi
    //         ->paginate($perPage);

    //     return response()->json($report);
    // }

    public function salesReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $search = $request->query('search');

        $query = TransactionDetail::query()
            ->select(
                'products.id',
                'products.code',
                'products.name',
                'products.image',
                'categories.name as category_name',
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('transactions.status', ['completed', 'refund_rejected']);

        if ($month && $year) {
            $query->whereMonth('transactions.created_at', $month)
                ->whereYear('transactions.created_at', $year);
        } elseif ($year) {
            $query->whereYear('transactions.created_at', $year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.code', 'like', "%{$search}%");
            });
        }

        // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
        $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
        ]);
    }

    public function trackOrder($id)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

        // [PERBAIKAN] Validasi menggunakan biteship_order_id
        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function bulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id'
        ]);

        // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
        foreach ($transactions as $transaction) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending']; // Fallback jika belum teralokasi
                }
            } catch (\Exception $e) {
                // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
        return response()->json($trackingData);
    }

    // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
    public function adminBulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id'
        ]);

        // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
        $transactions = Transaction::whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        foreach ($transactions as $transaction) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key')
                ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];
                }
            } catch (\Exception $e) {
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        return response()->json($trackingData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id); // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get("https://api.biteship.com/v1/orders/" . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        if (!$transaction->biteship_order_id) {
            return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
        }

        // Ambil query parameter dari Vue (insurance_shown, dll)
        $queryString = http_build_query($request->all());

        // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
        $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

        try {
            // Tembak URL label Biteship dengan API Key kita
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => config('services.biteship.api_key')
            ])->get($biteshipUrl);

            // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
            }

            return response()->json(['message' => 'Gagal mengambil resi dari Biteship: ' . $response->body()], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
        }
    }

    // public function biteshipCallback(Request $request)
    // {
    //     // Biteship mengirimkan token otentikasi di Header untuk keamanan
    //     $biteshipSignature = $request->header('biteship-signature');
    //     // Validasi signature jika perlu (Opsional tapi disarankan di Production)

    //     $event = $request->input('event'); // Contoh: 'order.status.updated' atau 'waybill.ready'
    //     $biteshipOrderId = $request->input('order_id');
    //     $status = $request->input('status'); // picking_up, dropped, delivered, dll
    //     $waybill = $request->input('courier_tracking_id'); // Ini adalah resi

    //     \Log::info('Biteship Webhook Received: ', $request->all());

    //     $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

    //     if (!$transaction) {
    //         return response()->json(['message' => 'Transaction not found'], 200);
    //     }

    //     // 1. Jika ada update Nomor Resi yang menyusul
    //     if ($waybill && $transaction->tracking_number === 'Pending') {
    //         $transaction->update(['tracking_number' => $waybill]);
    //     }

    //     // 2. Jika Anda ingin Auto-Complete transaksi saat kurir mengubah status jadi 'delivered'
    //     // (Ini opsional, karena Anda sudah punya tombol "Order Received" untuk ditekan user)
    //     if ($status === 'delivered' && $transaction->status === 'processing') {
    //         $transaction->update(['status' => 'completed']);
    //     }

    //     return response()->json(['message' => 'Webhook processed']);
    // }

    // public function biteshipCallback(Request $request)
    // {
    //     // Validasi signature (Opsional tapi disarankan)
    //     $biteshipSignature = $request->header('biteship-signature');

    //     $biteshipOrderId = $request->input('order_id');
    //     $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected
    //     $waybill = $request->input('courier_waybill_id');

    //     \Log::info('Biteship Webhook Received: ', $request->all());

    //     $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

    //     if (!$transaction) {
    //         return response()->json(['message' => 'Transaction not found'], 200);
    //     }

    //     // 1. Update Resi jika baru turun
    //     if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
    //         $transaction->update(['tracking_number' => $waybill]);
    //     }

    //     // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
    //     if ($status === 'delivered' && $transaction->status === 'processing') {
    //         $transaction->update(['status' => 'completed']);
    //     }

    //     // 3. [BARU] Jika logistik membatalkan pengiriman SEPIHAK (sebelum sampai ke pembeli)
    //     // Hal ini biasanya terjadi jika kurir tidak menemukan alamat origin, paket terlalu besar, dll.
    //     if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
    //         // Ubah status ke manual refund required, karena pembeli sudah bayar, tapi barang gagal jalan.
    //         // Admin harus mengecek mengapa logistik gagal, lalu me-refund manual atau memesan kurir ulang.
    //         $transaction->update([
    //             'status' => 'refund_manual_required',
    //             'tracking_number' => 'Logistics Cancelled/Rejected'
    //         ]);
    //         \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
    //     }

    //     if ($status === 'disposed' && $transaction->status === 'processing') {
    //         // Ubah status ke shipping failed, karena pembeli sudah bayar, tapi barang rusak di tengah jalan.
    //         // Admin harus mengembalikan uang pembeli.
    //         $transaction->update([
    //             'status' => 'shipping_failed',
    //             'tracking_number' => 'Shipping Failed'
    //         ]);
    //         \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
    //     }

    //     if ($status === 'returned' && $transaction->status === 'processing') {
    //         // Ubah status ke returned, karena user tidak jadi membeli dan barang telah dikembalikan.
    //         // Admin harus mengembalikan uang pembeli.
    //         $transaction->update([
    //             'status' => 'returned',
    //             'tracking_number' => 'Shipping Returned'
    //         ]);
    //         \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
    //     }

    //     return response()->json(['message' => 'Webhook processed successfully']);
    // }

    public function biteshipCallback(Request $request)
    {
        // Validasi signature (Opsional tapi disarankan)
        $biteshipSignature = $request->header('biteship-signature');

        $biteshipOrderId = $request->input('order_id');
        $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
        $waybill = $request->input('courier_waybill_id');

        \Log::info('Biteship Webhook Received: ', $request->all());

        $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 200);
        }

        // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
        $updates = ['shipping_status' => $status];

        // 1. Update Resi jika baru turun
        if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
            $updates['tracking_number'] = $waybill;
        }

        // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
        if ($status === 'delivered' && $transaction->status === 'processing') {
            $updates['status'] = 'completed';

            // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
            $transaction->update($updates);

            // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
            $this->checkAndAssignMembership($transaction->user);

            // Refresh data user
            $transaction->user->refresh();

            // Tambah poin user jika dia member dan transaksi punya poin
            if ($transaction->point > 0 && $transaction->user->is_membership) {
                $transaction->user->increment('point', $transaction->point);
            }

            return response()->json(['message' => 'Webhook processed and membership checked']);
        }

        // 3. Jika logistik membatalkan pengiriman SEPIHAK
        if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
            $updates['status'] = 'refund_manual_required';
            $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
            \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
        }

        if ($status === 'disposed' && $transaction->status === 'processing') {
            $updates['status'] = 'shipping_failed';
            $updates['tracking_number'] = 'Shipping Failed';
            \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
        }

        if ($status === 'returned' && $transaction->status === 'processing') {
            $updates['status'] = 'returned';
            $updates['tracking_number'] = 'Shipping Returned';
            \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
        }

        // Eksekusi semua update ke database dalam 1 query
        $transaction->update($updates);

        return response()->json(['message' => 'Webhook processed successfully']);
    }

    // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
    private function checkAndAssignMembership($user)
    {
        // Jika user sudah member, tidak perlu cek lagi
        if ($user->is_membership) return;

        // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

        // Jika total belanja >= 100.000, jadikan member
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }
}
