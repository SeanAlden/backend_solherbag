<?php

// namespace App\Http\Controllers;

// use App\Models\Category;
// use Illuminate\Http\Request;
// use App\Http\Controllers\Controller;
// use Illuminate\Support\Facades\Validator;

// class CategoryController extends Controller
// {
//     public function indexGuest()
//     {
//         return response()->json(Category::all(), 200);
//     }
//     public function index()
//     {
//         return response()->json(Category::all(), 200);
//     }

//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'code' => 'required|unique:categories',
//             'name' => 'required',
//         ]);
//         if ($validator->fails()) return response()->json($validator->errors(), 422);

//         $category = Category::create($request->all());
//         return response()->json($category, 201);
//     }

//     public function update(Request $request, $id)
//     {
//         $category = Category::findOrFail($id);
//         $category->update($request->all());
//         return response()->json($category, 200);
//     }

//     public function destroy($id)
//     {
//         Category::destroy($id);
//         return response()->json(['message' => 'Kategori dihapus'], 200);
//     }
// }

namespace App\Http\Controllers;

use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;

class CategoryController extends Controller
{
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function index()
    {
        $categories = $this->categoryService->getAllCategories();
        return CategoryResource::collection($categories);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return (new CategoryResource($category))
                ->response()
                ->setStatusCode(201);
    }

    public function update(CategoryRequest $request, $id): CategoryResource
    {
        $category = \App\Models\Category::findOrFail($id);
        $updated = $this->categoryService->updateCategory($category, $request->validated());

        return new CategoryResource($updated);
    }

    // public function destroy($id): JsonResponse
    // {
    //     $this->categoryService->deleteCategory($id);

    //     return response()->json(['message' => 'Category and its cache successfully cleared.']);
    // }

    public function destroy($id): JsonResponse
    {
        try {
            $this->categoryService->deleteCategory($id);
            return response()->json(['message' => 'Category successfully deleted.']);
        } catch (\Exception $e) {
            if ($e->getCode() === 409) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return response()->json(['message' => 'Internal Server Error'], 500);
        }
    }
}

