<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Mail\PasswordReset;
use App\Mail\SendCustomEmail;
use App\Models\Course;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
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
                    $query->select('id', 'title', 'type', 'price', 'status', 'start_date', 'updated_at', 'category_id', 'image')
                        ->orderBy('updated_at', 'desc');
                }, 'course.category' => function ($query) {
                    $query->select('id', 'name');
                }])
                ->get();

            // Split courses by type
            $paidCourses = $transactions->where('course.type', 'Course')->pluck('course')->unique('id')->values();
            $paidLiveTeaching = $transactions->where('course.type', 'Live_Teaching')->pluck('course')->unique('id')->values();

            // Get courses where user has answered questions
            $answeredCourseIds = UserAnswer::where('user_id', $user->id)->pluck('course_id')->unique()->values();

            // Filter paidCBT to exclude courses with answers
            $paidCBT = $transactions->where('course.type', 'CBT')
                ->pluck('course')
                ->unique('id')
                ->reject(function ($course) use ($answeredCourseIds) {
                    return $answeredCourseIds->contains($course->id);
                })
                ->values();

            \Log::info('Dashboard Query Result:', [
                'email' => $user->email,
                'typeCounts' => $typeCounts,
                'paidCoursesCount' => $paidCourses->count() + $paidCBT->count() + $paidLiveTeaching->count(),
                'answeredCourseIds' => $answeredCourseIds,
                'paidCBTCount' => $paidCBT->count()
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

    public function transactionDetails(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Retrieve transaction by id for the authenticated user's email
            $transaction = Transaction::where('email', $user->email)->with(['course' => function ($query) {
                $query->select(['id', 'title', 'type']);
            }])->where('id', $id)->firstOrFail();

            return response()->json([
                'message' => 'Transaction retrieved successfully',
                'data' => $transaction
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Transaction Not Found:', ['user_id' => $user->id ?? null, 'transaction_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Transaction not found'], 404);
        } catch (\Exception $e) {
            Log::error('Failed to Retrieve Transaction:', ['user_id' => $user->id ?? null, 'transaction_id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve transaction data'], 500);
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

    public function submitAnswers(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|integer|exists:courses,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:course_questions,id',
            'answers.*.answer_id' => 'required|integer|exists:question_answers,id',
        ]);

        if ($validator->fails()) {
            Log::warning('Submit Answers Validation Failed:', ['errors' => $validator->errors()->toArray(), 'input' => $request->all()]);
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $user = $request->user();
            if (!$user->role == 'user') {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $courseId = $request->course_id;
            $answers = $request->answers;

            // Store user answers
            $userAnswers = [];
            foreach ($answers as $answer) {
                $userAnswer = UserAnswer::create([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                    'question_id' => $answer['question_id'],
                    'answer_id' => $answer['answer_id'],
                    'is_correct' => false, // Will be updated below
                ]);
                $userAnswers[] = $userAnswer;
            }

            // Fetch course questions and correct answers
            $course = Course::with(['questions.answers' => function ($query) {
                $query->where('is_true', true);
            }])->findOrFail($courseId);

            $correctAnswers = $course->questions->flatMap->answers->keyBy('question_id')->map->id;

            // Check correctness and update is_correct
            $correctCount = 0;
            $totalQuestions = $course->questions->count();
            foreach ($userAnswers as $userAnswer) {
                $isCorrect = $correctAnswers->get($userAnswer->question_id) == $userAnswer->answer_id;
                $userAnswer->update(['is_correct' => $isCorrect]);
                if ($isCorrect) {
                    $correctCount++;
                }
            }

            // Calculate score (percentage)
            $score = $totalQuestions > 0 ? ($correctCount / $totalQuestions) * 100 : 0;

            Log::info('Answers Submitted Successfully:', [
                'user_id' => $user->id,
                'course_id' => $courseId,
                'score' => $score,
                'correct_count' => $correctCount,
                'total_questions' => $totalQuestions,
            ]);

            return response()->json([
                'message' => 'Answers submitted and scored successfully',
                'data' => [
                    'score' => number_format($score, 2) . '%',
                    'correct_count' => $correctCount,
                    'total_questions' => $totalQuestions,
                    'user_answers' => $userAnswers,
                ],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Course or Question Not Found:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Course or question data not found'], 404);
        } catch (\Exception $e) {
            Log::error('Submit Answers Failed:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to process answers: ', $e->getMessage()], 500);
        }
    }

    public function updateProfilePicture(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Validate request
            $request->validate([
                'profile_picture' => 'required|image|max:2048', // Max 2MB
            ]);

            // Handle file upload
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('profile_pictures', $fileName, 'public');

                // Delete old profile picture if exists
                if ($user->profile_picture) {
                    Storage::disk('public')->delete($user->profile_picture);
                }

                // Update user with new profile picture path
                $user->update(['profile_picture' => $path]);
            }

            return response()->json([
                'message' => 'Profile picture updated successfully',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update profile picture'], 500);
        }
    }

    public function sendEmail(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'to' => 'required|email',
                'subject' => 'required|string',
                'body' => 'required|string',
            ]);

            $details = [
                'to' => $request->input('to'),
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
            ];

            // Send email
            Mail::to($details['to'])->send(new SendCustomEmail($details));

            Log::info('Email Sent Successfully:', [
                'to' => $details['to'],
                'subject' => $details['subject'],
            ]);

            return response()->json([
                'message' => 'Email sent successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to Send Email:', [
                'to' => $request->input('to'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }

    public function sendPasswordResetLink(Request $request)
    {
        try {
            // Validate email
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $email = $request->input('email');

            // Send password reset link
            $status = Password::sendResetLink(['email' => $email], function ($user, $token) use ($email) {
                $details = [
                    'to' => $email,
                    'token' => $token,
                    'fullname' => $user->fullname,
                ];
                Mail::to($email)->send(new PasswordReset($details));
            });

            if ($status === Password::RESET_LINK_SENT) {
                Log::info('Password Reset Link Sent:', ['email' => $email]);
                return response()->json(['message' => 'Password reset link sent to your email'], 200);
            } else {
                Log::warning('Password Reset Link Failed:', ['email' => $email, 'status' => $status]);
                return response()->json(['error' => 'Unable to send password reset link'], 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Password Reset Validation Failed:', [
                'email' => $request->input('email'),
                'errors' => $e->errors(),
            ]);
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to Send Password Reset Link:', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to send password reset link: ' . $e->getMessage()], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'token' => 'required|string',
                'email' => 'required|email|exists:users,email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $credentials = $request->only('email', 'token', 'password');

            $status = Password::reset($credentials, function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            });

            if ($status === Password::PASSWORD_RESET) {
                Log::info('Password Reset Successfully:', ['email' => $request->email]);
                return response()->json(['message' => 'Password has been reset successfully'], 200);
            } else {
                Log::warning('Password Reset Failed:', ['email' => $request->email, 'status' => $status]);
                return response()->json(['error' => 'Unable to reset password'], 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Password Reset Validation Failed:', [
                'email' => $request->email,
                'errors' => $e->errors(),
            ]);
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to Reset Password:', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to reset password: ' . $e->getMessage()], 500);
        }
    }

    public function redirectToResetPassword($token, $email)
    {
        return redirect(config('app.web_url') . '/set-password?token=' . $token . '&email=' . $email);
    }
}
