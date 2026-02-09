<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryService
{

    protected $cacheKey = 'categories_all';
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function getAllCategories()
    {
        // Cache data selama 24 jam
        return Cache::remember($this->cacheKey, now()->addDay(), function () {
            return Category::latest()->get();
        });
    }

    public function createCategory(array $data)
    {
        $category = Category::create($data);
        $this->clearCache();
        return $category;
    }

    public function updateCategory(Category $category, array $data)
    {
        $category->update($data);
        $this->clearCache();
        return $category->fresh();
    }

    // public function deleteCategory($id)
    // {
    //     $category = Category::findOrFail($id);
    //     $category->delete();
    //     $this->clearCache();
    //     return true;
    // }

    public function deleteCategory($id)
    {
        $category = Category::findOrFail($id);

        if ($category->products()->exists()) {
            throw new \Exception("Cannot delete category because it contains products.", 409);
        }

        $category->delete();
        $this->clearCache();
        return true;
    }

    protected function clearCache()
    {
        Cache::forget($this->cacheKey);
    }
}
