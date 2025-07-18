<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionController extends Controller
{

    function createTransaction(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|array',
            'course_id.*' => 'exists:courses,id',
            'fullname' => 'required|string',
            'email' => 'required|email',
            'total' => 'required|numeric|min:0',
            'phone_number' => 'required|string|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $courseIds = $request->input('course_id');
        $userEmail = $request->email;
        $total = $request->total;

        // Check capacity for each course
        $capacityIssues = [];
        foreach ($courseIds as $courseId) {
            $course = Course::findOrFail($courseId);
            $applicantCount = Transaction::where('course_id', $courseId)->where('status', 'paid')->count();
            if ($applicantCount >= $course->max_student) {
                $capacityIssues[$courseId] = $course->max_student;
            }
        }

        if (!empty($capacityIssues)) {
            $message = 'The following courses have reached their maximum student capacity: ' .
                implode(', ', array_map(function ($id, $limit) {
                    return "Course ID $id ($limit)";
                }, array_keys($capacityIssues), $capacityIssues)) . '.';
            return response()->json(['message' => $message], 400);
        }

        $client = new Client();
        $serverKey = config('midtrans.server_key') . ':';
        $authHeader = 'Basic ' . base64_encode($serverKey);

        $prefix = "JP-";
        $date = date('ymd'); // Optimized date format
        $random = bin2hex(random_bytes(2));
        $order_id = "{$prefix}{$date}-{$random}";

        // Convert total to cents (Midtrans expects integer in smallest unit)
        $grossAmount = 0;

        try {
            DB::beginTransaction();

            // Create a transaction record for each course_id
            foreach ($courseIds as $courseId) {
                $course = Course::findOrFail($courseId);
                $discount = $course->discount_type === 'PERCENTAGE' ? $course->price * ($course->discount / 100) : $course->discount;
                $price = $course->price -  $discount;
                $grossAmount += $price;
                Transaction::create([
                    'order_id' => $order_id,
                    'email' => $userEmail,
                    'course_id' => $courseId,
                    'total' => $price,
                    'type' => 'midtrans',
                    'notes' => $request->input('notes', null),
                ]);
            }

            $params = [
                'transaction_details' => [
                    'order_id' => $order_id,
                    'gross_amount' => $grossAmount,
                ],
                'customer_details' => [
                    'first_name' => $request->fullname,
                    'email' => $userEmail,
                    'phone' => $request->phone_number,
                ],
            ];

            $jsonStr = json_encode($params, JSON_UNESCAPED_SLASHES);

            $response = $client->request('POST', config('midtrans.url'), [
                'body' => $jsonStr,
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $authHeader,
                    'content-type' => 'application/json',
                ],
                'timeout' => 10, // Add timeout to prevent hanging
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
                throw new \Exception('Midtrans API returned non-success status: ' . $response->getStatusCode());
            }

            DB::commit();

            Log::info('Midtrans Transaction Success:', [
                'order_id' => $order_id,
                'course_ids' => $courseIds,
                'response' => $data,
            ]);

            return response()->json([
                'data' => $data,
                'message' => 'Transactions inserted successfully',
            ], 200);
        } catch (GuzzleException $e) {
            DB::rollBack();
            Log::error('Midtrans Request Error:', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'params' => $params,
            ]);
            return response()->json(['error' => 'Failed to create transaction: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction Processing Error:', [
                'error' => $e->getMessage(),
                'order_id' => $order_id,
                'params' => $params,
            ]);
            return response()->json(['error' => 'Failed to process transaction: ' . $e->getMessage()], 500);
        }
    }

    function callbackMidtrans(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'transaction_status' => 'required|string',
            'payment_type' => 'required|string',
            'order_id' => 'required|string',
            'fraud_status' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::warning('Invalid Notification Data:', ['errors' => $validator->errors(), 'input' => $request->all()]);
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            // Extract notification data
            $transaction = $request->transaction_status;
            $type = $request->payment_type;
            $order_id = $request->order_id;
            $fraud = $request->fraud_status;

            // Find all transactions with the same order_id
            $transactions = Transaction::where('order_id', $order_id)->get();

            if ($transactions->isEmpty()) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }

            // Status mapping with optimized logic
            $statusMap = [
                'capture' => fn() => $type === 'credit_card' && $fraud === 'accept' ? $transactions->each->update(['status' => 'paid']) : null,
                'settlement' => fn() => $transactions->each->update(['status' => 'paid']),
                'pending' => fn() => $transactions->each->update(['status' => 'pending']),
                'deny' => fn() => $transactions->each->update(['status' => 'failed']),
                'expire' => fn() => $transactions->each->update(['status' => 'failed']),
                'cancel' => fn() => $transactions->each->update(['status' => 'failed']),
            ];

            // Process status update
            $statusUpdated = false;
            if (isset($statusMap[$transaction])) {
                $statusMap[$transaction]();
                $statusUpdated = true;
            }

            if (!$statusUpdated) {
                Log::warning('Unknown or unhandled transaction status:', ['status' => $transaction, 'order_id' => $order_id]);
                return response()->json(['message' => 'Unknown or unhandled transaction status'], 200);
            }

            // Log the first transaction's status as a representative (all should be the same)
            Log::info('Notification Processed:', ['order_id' => $order_id, 'new_status' => $transactions->first()->status]);
            return response()->json(['message' => 'Notification processed successfully'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Transaction Not Found:', ['order_id' => $order_id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Transaction not found'], 404);
        } catch (\Exception $e) {
            Log::error('Notification Processing Error:', ['order_id' => $order_id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to process notification: ' . $e->getMessage()], 500);
        }
    }
}
