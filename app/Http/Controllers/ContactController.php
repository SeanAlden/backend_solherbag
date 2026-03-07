<?php

// namespace App\Http\Controllers;

// use App\Models\Contact;
// use Illuminate\Http\Request;
// use App\Http\Controllers\Controller;
// use Illuminate\Support\Facades\Validator;

// class ContactController extends Controller
// {
//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'name' => 'required|string|max:255',
//             'email' => 'required|email|max:255',
//             'phone' => 'nullable|string|max:20',
//             'description' => 'required|string',
//         ]);

//         if ($validator->fails()) {
//             return response()->json($validator->errors(), 422);
//         }

//         // Cek apakah request dikirim dengan token auth
//         $userId = auth('sanctum')->id();

//         $contact = Contact::create([
//             'user_id' => $userId, // Akan bernilai null jika guest
//             'name' => $request->name,
//             'email' => $request->email,
//             'phone' => $request->phone,
//             'description' => $request->description,
//         ]);

//         return response()->json(['message' => 'Message sent successfully!'], 201);
//     }

//     public function getInboundMessages()
//     {
//         // Mengambil semua pesan terbaru
//         $messages = \App\Models\Contact::latest()->get();
//         return response()->json($messages, 200);
//     }
// }

// namespace App\Http\Controllers;

// use App\Services\ContactService;
// use Illuminate\Http\JsonResponse;
// use App\Http\Controllers\Controller;
// use App\Http\Requests\ContactRequest;
// use App\Http\Resources\ContactResource;

// class ContactController extends Controller
// {
//     protected $contactService;

//     public function __construct(ContactService $contactService)
//     {
//         $this->contactService = $contactService;
//     }

//     public function store(ContactRequest $request): JsonResponse
//     {
//         $this->contactService->storeInboundMessage($request->validated());

//         return response()->json([
//             'status' => 'success',
//             'message' => 'Your inquiry has been received. Our team will contact you shortly.'
//         ], 201);
//     }


//     public function getInboundMessages()
//     {
//         $messages = $this->contactService->getMessagesForAdmin();

//         return ContactResource::collection($messages);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use App\Mail\AdminResponseMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    // ... (Fungsi store tetap sama, gunakan yang lama)
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'email' => 'required|email',
            'phone' => 'nullable',
            'description' => 'required'
        ]);

        if ($request->user('sanctum')) {
            $data['user_id'] = $request->user('sanctum')->id;
        }

        Contact::create($data);

        return response()->json(['message' => 'Message sent successfully'], 201);
    }

    // Fungsi Admin mengambil semua pesan
    public function getInboundMessages()
    {
        // Ubah langsung hit ke model agar data lengkap sesuai DB terbaca di Vue
        $messages = Contact::with('user')->latest()->get();
        return response()->json($messages);
    }

    // [BARU] Fungsi Admin melihat detail (Sekaligus mark as read)
    public function showAdminMessage($id)
    {
        $contact = Contact::with('user')->findOrFail($id);

        // Jika belum dibaca, ubah jadi sudah dibaca saat dibuka
        if (!$contact->is_read) {
            $contact->update(['is_read' => true]);
        }

        return response()->json($contact);
    }

    // [BARU] Fungsi Admin membalas pesan
    public function respondMessage(Request $request, $id)
    {
        $request->validate(['response' => 'required|string']);

        $contact = Contact::findOrFail($id);

        $contact->update([
            'response' => $request->response,
            'is_read' => true // Pastikan juga ter-read
        ]);

        // Kirim Email
        try {
            Mail::to($contact->email)->send(new AdminResponseMail($contact));
        } catch (\Exception $e) {
            // Lanjutkan saja meskipun email gagal, data tetap tersimpan di web
            \Log::error('Gagal kirim email kontak: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Response sent successfully']);
    }

    // [BARU] Fungsi User melihat riwayat pesan mereka sendiri
    public function userHistory(Request $request)
    {
        $messages = Contact::where('user_id', $request->user()->id)->latest()->get();
        return response()->json($messages);
    }

    // public function subscribe(Request $request)
    // {
    //     // Validasi 'email:rfc,dns' akan mengecek apakah domain email tersebut benar-benar aktif/punya mail server
    //     $request->validate([
    //         'email' => 'required|email:rfc,dns'
    //     ], [
    //         'email.dns' => 'The email domain does not seem to be valid or active.'
    //     ]);

    //     $email = $request->email;

    //     // Kirim email Welcome
    //     try {
    //         \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\WelcomeSubscriberMail($email));
    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('Gagal kirim email subscribe: ' . $e->getMessage());
    //         return response()->json(['message' => 'Failed to send confirmation email. Is your email correct?'], 500);
    //     }

    //     return response()->json([
    //         'message' => 'Subscription successful! We have sent a welcome email to your inbox.'
    //     ], 200);
    // }

    public function subscribe(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'email' => 'required|email:rfc,dns'
        ], [
            'email.dns' => 'The email domain does not seem to be valid or active.'
        ]);

        $email = $request->email;

        // Cek apakah ini email dari user yang sudah terdaftar di web kita
        $user = \App\Models\User::where('email', $email)->first();
        $isRegistered = $user ? true : false;

        $subscriber = \App\Models\Subscriber::where('email', $email)->first();

        if ($subscriber) {
            if (!$subscriber->is_active) {
                $subscriber->update(['is_active' => true, 'is_registered' => $isRegistered]);
            } else {
                return response()->json(['message' => 'You are already subscribed!'], 400);
            }
        } else {
            \App\Models\Subscriber::create([
                'email' => $email,
                'is_registered' => $isRegistered,
                'is_active' => true
            ]);
        }

        // Jika dia user terdaftar, update status di tabel users juga
        if ($user) {
            $user->update(['is_subscribed' => true]);
        }

        // Kirim Email Welcome (Sama seperti sebelumnya)
        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\WelcomeSubscriberMail($email));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Subscribe Mail Error: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Subscription successful! Welcome to our newsletter.']);
    }
}
