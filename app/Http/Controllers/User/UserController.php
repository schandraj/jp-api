<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class UserController extends Controller
{
    function dashboard(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Define the types to process
            $types = ['Live_Teaching', 'CBT', 'Course'];

            // Single query for counts by type with qualified status
            $counts = Transaction::where('email', $user->email)
                ->where('transactions.status', 'paid') // Qualified status column
                ->join('courses', 'transactions.course_id', '=', 'courses.id')
                ->whereIn('courses.type', $types)
                ->select('courses.type', \DB::raw('COUNT(*) as count'))
                ->groupBy('courses.type')
                ->get()
                ->pluck('count', 'type')
                ->all();

            // Initialize counts with 0 for all types
            $typeCounts = array_fill_keys($types, 0);
            $typeCounts = array_merge($typeCounts, $counts);

            // Single query for all paid courses with categories, sorted by updated_at
            $transactions = Transaction::where('email', $user->email)
                ->where('transactions.status', 'paid') // Qualified status column
                ->with(['course' => function ($query) {
                    $query->select('id', 'title', 'type', 'price', 'status', 'updated_at', 'category_id', 'image')
                        ->orderBy('updated_at', 'desc');
                }, 'course.category' => function ($query) {
                    $query->select('id', 'name');
                }])
                ->get();

            // Split courses by type
            $paidCourses = $transactions->where('course.type', 'Course')->pluck('course')->unique('id')->values();
            $paidCBT = $transactions->where('course.type', 'CBT')->pluck('course')->unique('id')->values();
            $paidLiveTeaching = $transactions->where('course.type', 'Live_Teaching')->pluck('course')->unique('id')->values();

            \Log::info('Dashboard Query Result:', [
                'email' => $user->email,
                'typeCounts' => $typeCounts,
                'paidCoursesCount' => $paidCourses->count() + $paidCBT->count() + $paidLiveTeaching->count()
            ]);

            return response()->json([
                'live_teaching_count' => $typeCounts['Live_Teaching'],
                'cbt_count' => $typeCounts['CBT'],
                'course_count' => $typeCounts['Course'],
                'course' => $paidCourses,
                'CBT' => $paidCBT,
                'live_teaching' => $paidLiveTeaching,
            ]);
        } catch (\Exception $e) {
            \Log::error('Dashboard Error:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve dashboard data: ' . $e->getMessage()], 500);
        }
    }
}
