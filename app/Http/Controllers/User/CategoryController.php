<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Category;
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
                    $query->where('type', 'Live_Teaching')->where('status', 'PUBLISHED')->where('start_date', '>', now());;
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
}
