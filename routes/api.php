<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\AddressController; // Pastikan ini di-import

// Route Public
Route::get('/home/find-product', [HomeController::class, 'getProductBySearch']);
Route::get('/home/category/{code}', [HomeController::class, 'getProductsByCategory']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/user/update-info', [AuthController::class, 'updateProfileInfo']);
    Route::post('/user/update-image', [AuthController::class, 'updateImage']);
    Route::post('/user/update-password', action: [AuthController::class, 'updatePassword']);
    Route::get('/admin/users', [AuthController::class, 'getAllUsers']);
    Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);
});

// Rute Publik (Bisa diakses tanpa login)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/inactive', [ProductController::class, 'inactiveProducts']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::post('/contact', [ContactController::class, 'store']);
Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);

Route::get('/guest/categories', [CategoryController::class, 'index']);

// Route Protected (Membutuhkan Login)
Route::middleware('auth:sanctum')->group(function () {
    // Info User
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Manajemen Alamat (Address)
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Hanya simpan rute manajemen admin di sini
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::delete('/products/{id}/force', [ProductController::class, 'forceDelete']);

    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::put('/carts/{id}', [CartController::class, 'update']);
    Route::delete('/carts/{id}', [CartController::class, 'destroy']);

    // Checkout (buat transaksi dari cart)
    Route::post('/checkout', [TransactionController::class, 'checkout']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/admin/transactions', [TransactionController::class, 'allTransactions']);

    // User Actions
    Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancelOrder']);
    Route::post('/transactions/{id}/confirm', [TransactionController::class, 'confirmComplete']);
    Route::post('/transactions/{id}/refund-request', [TransactionController::class, 'requestRefund']);
    Route::post('/transactions/{id}/refund-process', [TransactionController::class, 'processRefundUser']);
    Route::post('/admin/transactions/{id}/refund-approve', [TransactionController::class, 'approveRefund']);
    Route::post('/admin/transactions/{id}/refund-reject', [TransactionController::class, 'rejectRefund']);

    // List transaksi user login
    // Route::put('/admin/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::get('/admin/transactions/{id}', [TransactionController::class, 'adminShow']);
    Route::get('/admin/sales-report', [TransactionController::class, 'salesReport']);
    Route::get('/transactions/{id}/tracking', action: [TransactionController::class, 'trackOrder']);
});

Route::post('/biteship/callback', [TransactionController::class, 'biteshipCallback']);

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin/dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'getStats']);
    Route::get('/revenue-chart', [DashboardController::class, 'getRevenueChart']);
    Route::get('/popular-products', [DashboardController::class, 'getPopularProducts']);
});

// Create Xendit Invoice (harus login)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payments/invoice', [PaymentController::class, 'createInvoice']);
});

// Xendit callback / webhook (tanpa auth)
Route::post('/payments/callback', [PaymentController::class, 'callback']);
Route::post('/shipping/rates', [PaymentController::class, 'getShippingRates']);

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    Route::get('/', function (Request $request) {
        return $request->user();
    });

    Route::post('/update-info', [AuthController::class, 'updateAdminProfileInfo']);
    Route::post('/update-image', [AuthController::class, 'updateAdminImage']);
    Route::post('/update-password', [AuthController::class, 'updateAdminPassword']);
    Route::put('/transactions/{id}/shipping', [TransactionController::class, 'simulateShipping']);
});
