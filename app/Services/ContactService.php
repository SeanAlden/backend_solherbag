<?php

namespace App\Services;

use App\Models\User;
use App\Models\Contact;

class ContactService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Menyimpan pesan inbound dengan pencocokan user otomatis.
     */
    public function storeInboundMessage(array $data): Contact
    {
        $userId = auth('sanctum')->id();

        if (!$userId) {
            $user = User::where('email', $data['email'])->first();
            $userId = $user ? $user->id : null;
        }

        $data['user_id'] = $userId;

        return Contact::create($data);
    }

    /**
     * Mengambil list pesan untuk dashboard admin dengan optimasi query.
     */
    public function getMessagesForAdmin()
    {
        // Menggunakan eager loading jika nanti ada relasi ke User
        return Contact::with('user')->latest()->paginate(15);
    }
}
