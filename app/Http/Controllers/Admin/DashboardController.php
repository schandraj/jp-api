<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    /**
     * Display dashboard statistics.
     */
    public function index(Request $request)
    {
        try {
            // Validate request (optional, no params needed)
            $validator = Validator::make($request->all(), []);

            if ($validator->fails()) {
                \Log::error('Validation errors:', $validator->errors()->toArray());
                return response()->json($validator->errors(), 422);
            }

            // Calculate dashboard statistics
            $stats = [
                'live_teaching' => Course::where('type', 'Live_Teaching')->count(),
                'cbt' => Course::where('type', 'CBT')->count(),
//                'sertifikat' => Course::whereHas('benefits', function ($query) {
//                    $query->where('name', 'like', '%Certificate%');
//                })->count(),
                'users' => User::count(),
                'courses' => Course::where('type', 'Course')->count(),
//                'total_earnings' => Course::where('status', 'PUBLISHED')
//                    ->sum('price'),
            ];

            return response()->json([
                'message' => 'Dashboard statistics retrieved successfully',
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve dashboard stats:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve dashboard stats: ' . $e->getMessage()], 500);
        }
    }
}
