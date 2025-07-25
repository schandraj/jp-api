<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories with pagination and course type counts.
     */
    public function index(Request $request)
    {
        try {
            // Validate the limit parameter
            $validator = Validator::make($request->all(), [
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // Set default limit if not provided
            $limit = $request->input('limit', 10);

            // Fetch categories with counts for each type
            $categories = Category::withCount([
                'courses as course_count' => function ($query) {
                    $query->where('type', 'Course')->where('status', 'PUBLISHED');
                },
                'courses as cbt_count' => function ($query) {
                    $query->where('type', 'CBT')->where('status', 'PUBLISHED');
                },
                'courses as live_teaching_count' => function ($query) {
                    $query->where('type', 'Live_Teaching')->where('status', 'PUBLISHED');
                },
            ])->paginate($limit);

            return response()->json([
                'message' => 'Categories retrieved successfully',
                'data' => $categories
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve categories: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $category = Category::create([
                'name' => $request->name,
            ]);

            return response()->json([
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create category'], 500);
        }
    }

    /**
     * Display the specified category.
     */
    public function show($id)
    {
        try {
            $category = Category::with('courses')->findOrFail($id);
            return response()->json([
                'message' => 'Category retrieved successfully',
                'data' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Category not found'], 404);
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $category = Category::findOrFail($id);
            $category->update([
                'name' => $request->name,
            ]);

            return response()->json([
                'message' => 'Category updated successfully',
                'data' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update category'], 500);
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            // Check if category has associated courses
            if ($category->courses()->count() > 0) {
                return response()->json(['error' => 'Cannot delete category with associated courses'], 422);
            }

            $category->delete();
            return response()->json(['message' => 'Category deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete category'], 500);
        }
    }
}
