<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
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
    Route::apiResource('categories', CategoryController::class);
    // Route::apiResource('products', ProductController::class);

    // Hanya simpan rute manajemen admin di sini
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
});
