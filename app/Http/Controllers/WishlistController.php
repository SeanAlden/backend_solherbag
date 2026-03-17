<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $wishlists = Wishlist::with('product')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($wishlists);
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $userId = $request->user()->id;
        $productId = $request->product_id;

        $wishlist = Wishlist::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($wishlist) {
            $wishlist->delete();
            return response()->json(['status' => 'removed', 'message' => 'Removed from favorites']);
        } else {
            Wishlist::create([
                'user_id' => $userId,
                'product_id' => $productId
            ]);
            return response()->json(['status' => 'added', 'message' => 'Added to favorites']);
        }
    }
}
