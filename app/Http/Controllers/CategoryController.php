<?php

namespace App\Http\Controllers;

use App\Models\Category;
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
        $category = Category::findOrFail($id);
        $updated = $this->categoryService->updateCategory($category, $request->validated());

        return new CategoryResource($updated);
    }

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

    // Refactor fungsi show
    public function show($id): CategoryResource
    {
        $category = $this->categoryService->getCategoryById($id);
        return new CategoryResource($category);
    }
}
