<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Cek apakah request dikirim dengan token auth
        $userId = auth('sanctum')->id();

        $contact = Contact::create([
            'user_id' => $userId, // Akan bernilai null jika guest
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'description' => $request->description,
        ]);

        return response()->json(['message' => 'Message sent successfully!'], 201);
    }

    public function getInboundMessages()
    {
        // Mengambil semua pesan terbaru
        $messages = \App\Models\Contact::latest()->get();
        return response()->json($messages, 200);
    }
}
