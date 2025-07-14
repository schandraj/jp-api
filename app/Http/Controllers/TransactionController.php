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
            'course_id' => 'required|exists:courses,id',
            'fullname' => 'required|string',
            'email' => 'required|email',
            'total' => 'required|numeric|min:0',
            'phone_number' => 'required|string|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Fetch course max_student and check applicant count
        $course = Course::findOrFail($request->course_id);
        $applicantCount = Transaction::where('course_id', $request->course_id)->where('status', 'paid')->count();

        if ($applicantCount >= $course->max_student) {
            return response()->json([
                'message' => 'This course has reached its maximum student capacity (' . $course->max_student . '). Transaction cannot be created.',
            ], 400);
        }

        $client = new Client();
        $serverKey = config('midtrans.server_key') . ':';
        $authHeader = 'Basic ' . base64_encode($serverKey);

        $prefix = "JP-";
        $date = date('ymd'); // Optimized date format
        $random = bin2hex(random_bytes(2));
        $order_id = "{$prefix}{$date}-{$random}";

        // Convert total to cents (Midtrans expects integer in smallest unit)
        $grossAmount = $request->total;

        $params = [
            'transaction_details' => [
                'order_id' => $order_id,
                'gross_amount' => $grossAmount,
            ],
            'customer_details' => [
                'first_name' => $request->fullname,
                'email' => $request->email,
                'phone' => $request->phone_number,
            ],
        ];

        $jsonStr = json_encode($params, JSON_UNESCAPED_SLASHES);

        try {
            DB::beginTransaction();

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

            Transaction::create([
                'order_id' => $order_id,
                'email' => $request->email,
                'course_id' => $request->course_id,
                'total' => $request->total,
                'type' => 'midtrans',
                'notes' => $request->notes
            ]);

            DB::commit();

            Log::info('Midtrans Transaction Success:', ['order_id' => $order_id, 'response' => $data]);

            return response()->json([
                'data' => $data,
                'message' => 'Transaction inserted successfully',
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

            // Find transaction with a single query
            $transLocal = Transaction::where('order_id', $order_id)->firstOrFail();

            // Status mapping with optimized logic
            $statusMap = [
                'capture' => fn() => $type === 'credit_card' && $fraud === 'accept' ? $transLocal->update(['status' => 'paid']) : null,
                'settlement' => fn() => $transLocal->update(['status' => 'paid']),
                'pending' => fn() => $transLocal->update(['status' => 'pending']),
                'deny' => fn() => $transLocal->update(['status' => 'failed']),
                'expire' => fn() => $transLocal->update(['status' => 'failed']),
                'cancel' => fn() => $transLocal->update(['status' => 'failed']),
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

            Log::info('Notification Processed:', ['order_id' => $order_id, 'new_status' => $transLocal->status]);
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
