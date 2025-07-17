<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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

    function profile(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            return response()->json(['user' => $user]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
    }

    public function profileUpdate(Request $request)
    {
        // Validate incoming request data with unique constraints
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'fullname' => 'sometimes|required|string|max:255',
            'phone_number' => 'sometimes|required|string|max:16|unique:users,phone_number,' . ($user ? $user->id : ''),
            'gender' => 'sometimes|required|in:male,female',
            'dob' => 'sometimes|required|date',
            'agency' => 'sometimes|required|string|max:255',
            'nik' => 'sometimes|required|string|max:16|unique:users,nik,' . ($user ? $user->id : ''),
            'address' => 'sometimes|required|string|max:255',
            'profession' => 'sometimes|required|string|max:255',
            'bio' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . ($user ? $user->id : ''),
        ]);

        if ($validator->fails()) {
            Log::warning('Profile Update Validation Failed:', ['errors' => $validator->errors()->toArray(), 'input' => $request->all()]);
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Prepare data for update, only including provided fields
            $updateData = $request->only([
                'fullname', 'phone_number', 'gender', 'dob', 'agency', 'nik', 'address', 'profession', 'bio', 'email'
            ]);

            Log::debug('Profile Update Data:', ['user_id' => $user->id, 'update_data' => $updateData]);

            // Fill user with new data and check if anything changed
            $user->fill($updateData);
            if ($user->isDirty()) {
                $user->save();
                Log::info('Profile Updated Successfully:', ['user_id' => $user->id, 'changes' => $updateData]);
            } else {
                Log::info('No Changes in Profile Update:', ['user_id' => $user->id]);
            }

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => $user->fresh(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database Error in Profile Update:', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'Failed to update profile data due to database issue'], 500);
        } catch (\Exception $e) {
            Log::error('Profile Update Failed:', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to update profile data'], 500);
        }
    }

    function transactions(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $transactions = Transaction::where('email', $user->email)->with(['course' => function ($query) {
                $query->select(['id', 'title', 'type']);
            }])->get();

            return response()->json(['transactions' => $transactions]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve transactions data: ' . $e->getMessage()], 500);
        }

    }

    public function changePassword(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            Log::warning('Change Password Validation Failed:', ['errors' => $validator->errors()->toArray(), 'input' => $request->all()]);
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['error' => 'Current password is incorrect'], 422);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            Log::info('Password Changed Successfully:', ['user_id' => $user->id]);

            return response()->json([
                'message' => 'Password changed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Change Password Failed:', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to change password'], 500);
        }
    }

    public function changeEmail(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'new_email' => 'required|email|unique:users,email,' . ($request->user() ? $request->user()->id : ''),
        ]);

        if ($validator->fails()) {
            Log::warning('Change Email Validation Failed:', ['errors' => $validator->errors()->toArray(), 'input' => $request->all()]);
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Update email
            $user->update([
                'email' => $request->new_email,
                'email_verified_at' => null, // Reset verification if using MustVerifyEmail
            ]);

            Log::info('Email Changed Successfully:', ['user_id' => $user->id, 'new_email' => $request->new_email]);

            return response()->json([
                'message' => 'Email changed successfully. Please verify your new email.',
            ]);
        } catch (\Exception $e) {
            Log::error('Change Email Failed:', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to change email'], 500);
        }
    }
}
