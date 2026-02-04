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
Route::post('/user/update', [AuthController::class, 'updateProfile']);

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
    Route::apiResource('products', ProductController::class);
});
