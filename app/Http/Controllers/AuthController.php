<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'password'   => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'User berhasil didaftarkan',
            'user'    => $user
        ], 201);
    }

    // public function login(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email'    => 'required|email',
    //         'password' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     $user = User::where('email', $request->email)->first();

    //     // Periksa apakah user ada dan password cocok
    //     if (!$user || !Hash::check($request->password, $user->password)) {
    //         return response()->json([
    //             'message' => 'Email atau Password salah.'
    //         ], 401);
    //     }

    //     // Buat Token menggunakan Sanctum
    //     $token = $user->createToken('auth_token')->plainTextToken;

    //     return response()->json([
    //         'message'      => 'Login Berhasil',
    //         'access_token' => $token,
    //         'token_type'   => 'Bearer',
    //         'user'         => $user
    //     ], 200);
    // }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::where('email', $request->email)->first();

        if (
            !$user ||
            !Hash::check($request->password, $user->password) ||
            $user->usertype !== 'user'
        ) {
            return response()->json([
                'message' => 'Email atau Password salah.'
            ], 401);
        }

        // Buat Token menggunakan Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Login Berhasil',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user
        ], 200);
    }

    public function adminLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::where('email', $request->email)
            ->where('usertype', 'admin') // Filter khusus admin
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Akses ditolak. Email/Password salah atau Anda bukan Admin.'
            ], 401);
        }

        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json([
            'message'      => 'Admin Login Berhasil',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user
        ], 200);
    }

    // 1. Update Nama & Email
    public function updateProfileInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        $user->update($request->only('first_name', 'last_name', 'email', 'phone'));

        return response()->json(['message' => 'Info profil diperbarui', 'user' => $user]);
    }

    public function updateImage(Request $request)
    {
        Log::info('Update profile image started', [
            'user_id' => $request->user()->id
        ]);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        $user = $request->user();

        try {
            // Jika ada foto lama
            if ($user->profile_image) {
                $oldPath = 'profiles/' . basename($user->profile_image);

                Log::info('Deleting old profile image', [
                    'user_id' => $user->id,
                    'old_path' => $oldPath
                ]);

                Storage::disk('s3')->delete($oldPath);
            }

            // Upload foto baru
            $path = $request->file('image')->store('profiles', [
                'disk' => 's3',
                'visibility' => 'public'
            ]);

            Log::info('New profile image uploaded', [
                'user_id' => $user->id,
                'new_path' => $path
            ]);

            // Simpan URL ke database
            // $user->profile_image = Storage::disk('s3')->url($path);
            // $user->save();

            // Log::info('Profile image updated successfully', [
            //     'user_id' => $user->id,
            //     'profile_image_url' => $user->profile_image
            // ]);

            // return response()->json([
            //     'message' => 'Foto profil diperbarui',
            //     'user' => $user
            // ]);
            $user->profile_image = Storage::disk('s3')->url($path);
            $user->save();

            // [TAMBAHAN PENTING]
            // Refresh model dari database untuk memastikan format URL/Date konsisten dengan endpoint lain
            $user = $user->fresh();

            Log::info('Profile image updated successfully', [
                'user_id' => $user->id,
                'profile_image_url' => $user->profile_image
            ]);

            return response()->json([
                'message' => 'Foto profil diperbarui',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update profile image', [
                'user_id' => $user->id ?? null,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Gagal memperbarui foto profil'
            ], 500);
        }
    }

    // 3. Update Password
    public function updatePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'password' => 'required|string|min:8|confirmed', // 'confirmed' mencari field password_confirmation
        ]);

        $user = $request->user();

        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'Password lama tidak sesuai'], 401);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password berhasil diubah']);
    }

    // Ambil semua daftar user biasa
    public function getAllUsers()
    {
        // Mengambil user dengan usertype 'user' saja
        $users = User::where('usertype', 'user')->latest()->get(); //
        return response()->json($users, 200);
    }

    // Ambil detail satu user beserta alamatnya
    public function getUserDetail($id)
    {
        // Memuat user beserta relasi addresses yang sudah kita buat sebelumnya
        $user = User::with('addresses')->findOrFail($id); //
        return response()->json($user, 200);
    }
}
