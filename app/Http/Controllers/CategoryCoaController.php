<?php

namespace App\Http\Controllers;

use App\Models\CategoryCoa;
use Illuminate\Http\Request;

class CategoryCoaController extends Controller
{
    public function index()
    {
        $categories = CategoryCoa::latest()->get();
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_name' => 'required|string|unique:category_coas,category_name'
        ]);

        $category = CategoryCoa::create($request->all());
        return response()->json($category, 201);
    }

    public function update(Request $request, $id)
    {
        $category = CategoryCoa::findOrFail($id);

        $request->validate([
            'category_name' => 'required|string|unique:category_coas,category_name,' . $id
        ]);

        $category->update($request->all());
        return response()->json($category);
    }

    public function destroy($id)
    {
        $category = CategoryCoa::findOrFail($id);

        if ($category->coas()->exists()) {
            return response()->json(['message' => 'Cannot delete because it contains COA records.'], 409);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted successfully']);
    }
}
