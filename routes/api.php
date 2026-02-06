<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AddressController; // Pastikan ini di-import

// Route Public
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
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::post('/contact', [ContactController::class, 'store']);
Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);

Route::get('/guest/categories', [CategoryController::class, 'indexGuest']);

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

    // Resource lainnya yang sudah dibuat sebelumnya
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Hanya simpan rute manajemen admin di sini
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/products/inactive', [ProductController::class, 'inactiveProducts']);
    Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::delete('/products/{id}/force', [ProductController::class, 'forceDelete']);

    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::put('/carts/{id}', [CartController::class, 'update']);
    Route::delete('/carts/{id}', [CartController::class, 'destroy']);

    // Checkout (buat transaksi dari cart)
    Route::post('/checkout', [TransactionController::class, 'checkout']);

    // List transaksi user login
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/admin/transactions', [TransactionController::class, 'allTransactions']);
    Route::put('/admin/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
});
