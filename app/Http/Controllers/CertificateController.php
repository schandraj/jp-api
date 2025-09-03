<?php

namespace App\Http\Controllers;

use App\Models\CertificateDownload;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CertificateController extends Controller
{
    public function downloadCertificate(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $courseId = $request->input('course_id');

            // Check if user has bought the course (optional, but recommended)
            $hasBought = Transaction::where('email', $user->email)->where('course_id', $courseId)->where('status', 'paid')->exists();
            if (!$hasBought) {
                return response()->json(['error' => 'You have not purchased this course'], 403);
            }

            // Check if certificate has already been downloaded
            $existingDownload = CertificateDownload::where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->first();
            if ($existingDownload) {
                return response()->json(['error' => 'Certificate has already been downloaded for this course'], 400);
            }

            // Store download record
            CertificateDownload::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
            ]);

            Log::info('Certificate Downloaded:', ['user_id' => $user->id, 'course_id' => $courseId]);

            // Return certificate file
            return response()->json(['message' => 'Certificate downloaded successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to Download Certificate:', [
                'user_id' => $user->id ?? null,
                'course_id' => $courseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to download certificate'], 500);
        }
    }
}
