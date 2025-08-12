<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CourseController extends Controller
{
    /**
     * Display a listing of courses with pagination and custom limit.
     */
    public function index(Request $request)
    {
        try {
            // Get query parameters with defaults
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $type = $request->input('type');
            $category = $request->input('category');
            $level = $request->input('level');
            $search = $request->input('search');
            $isFree = $request->input('is_free', 'false'); // Default to 'false'

            // Validate limit and page
            $validator = Validator::make(['limit' => $limit, 'page' => $page], [
                'limit' => 'integer|min:1|max:100',
                'page' => 'integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // Build the query
            $query = Course::query()->where('status', 'PUBLISHED');

            // Apply filters
            if ($type) {
                $query->where('type', $type);
            }
            if ($category) {
                $query->where('category_id', $category);
            }
            if ($level) {
                $query->where('course_level', $level);
            }
            if ($search) {
                $query->where('title', 'like', "%{$search}%");
            }
            if ($isFree === 'true') {
                $query->where('price', 0);
            }

            // Eager load questions count and applicant count (only paid transactions)
            $query->withCount(['questions', 'transactions as student_count' => function ($query) {
                $query->where('status', 'paid');
            }]);

            // Paginate results
            $courses = $query->paginate($limit);

            return response()->json([
                'message' => 'Courses retrieved successfully',
                'data' => $courses,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve courses:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve courses: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified course.
     */
    public function show($id)
    {
        try {
            $course = Course::with(['category', 'topics.lessons', 'crossSells.crossCourse' => function ($query) {
                $query->select('id', 'title', 'image', 'category_id', 'course_level', 'price', 'status');
            }, 'benefits', 'questions'])
                ->withCount(['questions','transactions as student_count' => function ($query) {
                $query->where('status', 'paid');
            }])->where('status', 'PUBLISHED')
                ->findOrFail($id);
            return response()->json([
                'message' => 'Course retrieved successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Course not found'], 404);
        }
    }

    public function getCbt($id)
    {
        try {
            $course = Course::with(['questions.answers' => function ($query) {
                $query->select('id','question_id','choice');
            }])->findOrFail($id);
            return response()->json([
                'message' => 'Course retrieved successfully',
                'data' => $course
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Course not found'], 404);
        }
    }
}
