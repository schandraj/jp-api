<?php

namespace App\Http\Controllers;

use App\Mail\PurchaseConfirmation;
use App\Mail\TransactionReminder;
use App\Models\Course;
use App\Models\Transaction;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionController extends Controller
{

    function createTransaction(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'courses' => 'required|array',
            'courses.*.course_id' => 'required_with:courses|exists:courses,id',
            'courses.*.price' => 'required_with:courses|numeric|min:0',
            'fullname' => 'required|string',
            'email' => 'required|email',
            'total' => 'required|numeric|min:0',
            'phone_number' => 'required|string|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $courses = $request->input('courses');
        $userEmail = $request->email;
        $total = $request->total;

        // Check capacity for each course
        $capacityIssues = [];
        foreach ($courses as $crs) {
            $course = Course::find($crs['course_id']); // Use find instead of findOrFail to handle gracefully
            if ($course) {
                $applicantCount = Transaction::where('course_id', $crs['course_id'])->where('status', 'paid')->count();
                if ($applicantCount >= $course->max_student) {
                    $capacityIssues[$crs['course_id']] = $course->max_student;
                }
            } else {
                $capacityIssues[$crs['course_id']] = 0; // Indicate course not found
            }
        }

        if (!empty($capacityIssues)) {
            $message = 'The following courses have issues: ' .
                implode(', ', array_map(function ($id, $limit) {
                    return $limit > 0 ? "Course ID $id has reached maximum student capacity ($limit)" : "Course ID $id not found";
                }, array_keys($capacityIssues), $capacityIssues)) . '.';
            return response()->json(['message' => $message], 400);
        }

        $client = new Client();
        $serverKey = config('midtrans.server_key') . ':';
        $authHeader = 'Basic ' . base64_encode($serverKey);

        $prefix = "JP-";
        $date = date('ymd');
        $random = bin2hex(random_bytes(2));
        $order_id = "{$prefix}{$date}-{$random}";

        // Convert total to cents (Midtrans expects integer in smallest unit)
        $grossAmount = 0;

        try {
            DB::beginTransaction();

            // Create a transaction record for each course_id
            foreach ($courses as $crs) {
                $price = $crs['price'];
                $grossAmount += $price;
                Transaction::create([
                    'order_id' => $order_id,
                    'email' => $userEmail,
                    'course_id' => $crs['course_id'],
                    'total' => $price,
                    'type' => 'midtrans',
                    'notes' => $request->input('notes', null),
                ]);
            }

            if ($grossAmount != $total) {
                throw new \Exception('Total amount mismatch: Calculated ' . $grossAmount . ' vs provided ' . $total);
            }

            $data = [];
            $redirectUrl = null;

            // Handle free transaction (total = 0) without Midtrans
            if ($total == 0) {
                Transaction::where('order_id', $order_id)->update(['status' => 'paid']);
                $transaction = Transaction::where('order_id', $order_id)->first();
                $course = Course::find($transaction->course_id);
                $courseTitle = $course ? $course->title : 'Unknown Course';
                $paymentMethod = 'Free'; // Assuming Midtrans as the payment method; adjust if dynamic
                $url = config('app.web_url');
                if ($course->type === 'CBT') {
                    $url = $url . '/student/cbt-instruction/' . $course->id;
                } elseif ($course->type === 'Course') {
                    $url = $url . '/student/course-content/' . $course->id;
                } elseif ($course->type === 'Live_Teaching') {
                    $url = $url . '/student/live-event/' . $course->id;
                }
                Mail::to($transaction->email)->send(new PurchaseConfirmation([
                    'name' => $request->fullname ?? 'User',
                    'course_title' => $courseTitle,
                    'total' => $transaction->total,
                    'payment_method' => $paymentMethod,
                    'url' => $url,
                ]));

                $data['is_free'] = true;
                $data['token'] = '';
                $data['redirect_url'] = '';
                $data['url_content'] = $url;
            } else {
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

                Log::debug('Midtrans Request:', ['params' => $params, 'url' => config('midtrans.url')]);
                $response = $client->request('POST', config('midtrans.url'), [
                    'body' => $jsonStr,
                    'headers' => [
                        'accept' => 'application/json',
                        'Authorization' => $authHeader,
                        'content-type' => 'application/json',
                    ],
                    'timeout' => 10,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
                    throw new \Exception('Midtrans API returned non-success status: ' . $response->getStatusCode() . ' - ' . json_encode($data));
                }

                // Update transactions with redirect_url from Midtrans response
                $redirectUrl = $data['redirect_url'] ?? null;
                if ($redirectUrl) {
                    Transaction::where('order_id', $order_id)->update(['redirect_url' => $redirectUrl]);
                }

                $courseTitle = Course::find($courses[0]['course_id'])->title ?? 'Unknown Course';
                Mail::to($userEmail)->send(new TransactionReminder([
                    'name' => $request->fullname,
                    'course_title' => $courseTitle,
                    'total' => $total,
                    'url' => config('app.web_url').'/login'
                ]));

                $data['is_free'] = false;
                $data['url_content'] = '';
            }

            DB::commit();

            Log::info('Midtrans Transaction Success:', [
                'order_id' => $order_id,
                'course_ids' => array_column($courses, 'course_id'),
                'response' => $data,
                'redirect_url' => $redirectUrl,
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
                'params' => $params ?? null,
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            return response()->json(['error' => 'Failed to create transaction: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction Processing Error:', [
                'error' => $e->getMessage(),
                'order_id' => $order_id ?? null,
                'params' => $params ?? null,
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

    public function checkTransactionStatus(Request $request)
    {
        try {
            // Validate request data
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                Log::warning('Invalid Status Check Request:', ['errors' => $validator->errors(), 'input' => $request->all()]);
                return response()->json(['error' => $validator->errors()], 422);
            }

            $orderId = $request->input('order_id');

            // Initialize Guzzle client and set up Midtrans API request
            $client = new Client();
            $serverKey = config('midtrans.server_key') . ':';
            $authHeader = 'Basic ' . base64_encode($serverKey);
            $statusUrl = config('midtrans.base_url') . '/v2/' . $orderId . '/status';

            Log::debug('Midtrans Status Check Request:', ['order_id' => $orderId, 'url' => $statusUrl]);
            $response = $client->request('GET', $statusUrl, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $authHeader,
                    'content-type' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Midtrans API returned non-success status: ' . $response->getStatusCode() . ' - ' . json_encode($data));
            }

            // Extract status from Midtrans response
            $transactionStatus = $data['transaction_status'] ?? null;
            $paymentType = $data['payment_type'] ?? null;
            $fraudStatus = $data['fraud_status'] ?? null;

            if (!$transactionStatus) {
                throw new \Exception('Invalid response from Midtrans: transaction_status not found');
            }

            // Find all transactions with the same order_id
            $transactions = Transaction::where('order_id', $orderId)->get();

            if ($transactions->isEmpty()) {
                throw new ModelNotFoundException('No transactions found for order ID: ' . $orderId);
            }

            // Status mapping with optimized logic
            $statusMap = [
                'capture' => fn() => $paymentType === 'credit_card' && $fraudStatus === 'accept' ? $transactions->each->update(['status' => 'paid']) : null,
                'settlement' => fn() => $transactions->each->update(['status' => 'paid']),
                'pending' => fn() => $transactions->each->update(['status' => 'pending']),
                'deny' => fn() => $transactions->each->update(['status' => 'failed']),
                'expire' => fn() => $transactions->each->update(['status' => 'failed']),
                'cancel' => fn() => $transactions->each->update(['status' => 'failed']),
            ];

            // Process status update
            $statusUpdated = false;
            if (isset($statusMap[$transactionStatus])) {
                $statusMap[$transactionStatus]();
                $statusUpdated = true;
            }

            if (!$statusUpdated) {
                Log::warning('Unknown or unhandled transaction status:', ['status' => $transactionStatus, 'order_id' => $orderId]);
            }

            // Log and return response
            Log::info('Transaction Status Checked:', [
                'order_id' => $orderId,
                'new_status' => $transactions->first()->status,
                'midtrans_response' => $data,
            ]);

            return response()->json([
                'message' => 'Transaction status checked successfully',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $transactions->first()->status,
                    'details' => $data,
                ]
            ], 200);
        } catch (GuzzleException $e) {
            Log::error('Midtrans Status Check Error:', [
                'order_id' => isset($orderId) ? $orderId : 'N/A',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            return response()->json(['error' => 'Failed to check transaction status: ' . $e->getMessage()], 500);
        } catch (ModelNotFoundException $e) {
            Log::error('Transaction Not Found:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Transaction not found: ' . $e->getMessage()], 404);
        } catch (Exception $e) {
            Log::error('Status Check Processing Error:', [
                'order_id' => isset($orderId) ? $orderId : 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to process status check: ' . $e->getMessage()], 500);
        }
    }

    public function updateTransaction(Request $request)
    {
        try {
            // Validate request data
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|string',
                'transaction_status' => 'required|string|in:capture,settlement,pending,deny,expire,cancel',
            ]);

            if ($validator->fails()) {
                Log::warning('Invalid Update Transaction Request:', ['errors' => $validator->errors(), 'input' => $request->all()]);
                return response()->json(['error' => $validator->errors()], 422);
            }

            $order_id = $request->input('order_id');
            $transaction_status = $request->input('transaction_status');

            // Find all transactions with the same order_id
            $transactions = Transaction::where('order_id', $order_id)->get();
            if ($transactions->isEmpty()) {
                throw new ModelNotFoundException('No transactions found for order ID: ' . $order_id);
            }

            // Status mapping with optimized logic
            $statusMap = [
                'capture' => ['status' => 'paid'],
                'settlement' => ['status' => 'paid'],
                'pending' => ['status' => 'pending'],
                'deny' => ['status' => 'failed'],
                'expire' => ['status' => 'failed'],
                'cancel' => ['status' => 'failed'],
            ];

            // Process status update
            $statusUpdated = false;
            $newStatus = null;
            if (isset($statusMap[$transaction_status])) {
                $newStatus = $statusMap[$transaction_status]['status'];
                $transactions->each->update(['status' => $newStatus]);
                $statusUpdated = true;
            }

            if (!$statusUpdated) {
                Log::warning('Unknown or unhandled transaction status:', ['status' => $transaction_status, 'order_id' => $order_id]);
                return response()->json(['error' => 'Unknown or unhandled transaction status'], 400);
            }

            // Send email if status is paid
            if ($newStatus === 'paid') {
                $transaction = $transactions->first();
                $course = \App\Models\Course::find($transaction->course_id);
                $courseTitle = $course ? $course->title : 'Unknown Course';
                $paymentMethod = 'Midtrans'; // Assuming Midtrans as the payment method; adjust if dynamic
                $url = config('app.web_url');
                if ($course->type === 'CBT') {
                    $url = $url . '/student/cbt-instruction/' . $course->id;
                } elseif ($course->type === 'Course') {
                    $url = $url . '/student/course-content/' . $course->id;
                } elseif ($course->type === 'Live_Teaching') {
                    $url = $url . '/student/live-event/' . $course->id;
                }
                Mail::to($transaction->email)->send(new PurchaseConfirmation([
                    'name' => $transaction->fullname ?? 'User', // Assuming fullname is in transactions or fetch from User model
                    'course_title' => $courseTitle,
                    'total' => $transaction->total,
                    'payment_method' => $paymentMethod,
                    'url' => $url,
                ]));
            }

            // Log the update
            Log::info('Transaction Status Updated:', [
                'order_id' => $order_id,
                'new_status' => $newStatus,
            ]);

            return response()->json([
                'message' => 'Transaction status updated successfully',
                'data' => [
                    'order_id' => $order_id,
                    'order_status' => $newStatus,
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Transaction Not Found:', ['order_id' => $order_id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Transaction not found: ' . $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::error('Update Transaction Error:', [
                'order_id' => $order_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to update transaction: ' . $e->getMessage()], 500);
        }
    }
}
