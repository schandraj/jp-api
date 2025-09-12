<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions with pagination.
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
            $page = $request->input('page', 1);
            $status = $request->input('status');
            $search = $request->input('search');

            $query = Transaction::query();
            if ($status) {
                $query->where('status', $status);
            }
            if ($search) {
                $query->where('order_id', 'like', "%{$search}%");
            }

            // Fetch transactions with pagination
            $transactions = $query->with('course')->orderBy('id', 'desc')->paginate($limit);

            return response()->json([
                'message' => 'Transactions retrieved successfully',
                'data' => $transactions
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve transactions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created transaction.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string|unique:transactions,order_id',
            'course_id' => 'required|exists:courses,id',
            'email' => 'required|email',
            'total' => 'required|numeric|min:0',
            'status' => 'required|in:pending,paid,failed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $transaction = Transaction::create([
                'order_id' => $request->order_id,
                'course_id' => $request->course_id,
                'email' => $request->email,
                'total' => $request->total,
                'status' => $request->status,
                'notes' => $request->notes,
                'type' => 'manual'
            ]);

            return response()->json([
                'message' => 'Transaction created successfully',
                'data' => $transaction
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create transaction: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show($id)
    {
        try {
            $transaction = Transaction::with('course')->findOrFail($id);
            return response()->json([
                'message' => 'Transaction retrieved successfully',
                'data' => $transaction
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }
    }

    /**
     * Update the specified transaction.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'sometimes|required|string|unique:transactions,order_id,' . $id,
            'course_id' => 'sometimes|required|exists:courses,id',
            'email' => 'sometimes|required|email',
            'total' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:pending,paid,failed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $transaction = Transaction::findOrFail($id);
            $transaction->update([
                'order_id' => $request->order_id ?? $transaction->order_id,
                'course_id' => $request->course_id ?? $transaction->course_id,
                'email' => $request->email ?? $transaction->email,
                'total' => $request->total ?? $transaction->total,
                'status' => $request->status ?? $transaction->status,
                'notes' => $request->notes ?? $transaction->notes,
            ]);

            return response()->json([
                'message' => 'Transaction updated successfully',
                'data' => $transaction
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update transaction: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified transaction.
     */
    public function destroy($id)
    {
        try {
            $transaction = Transaction::findOrFail($id);
            $transaction->delete();
            return response()->json(['message' => 'Transaction deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete transaction: ' . $e->getMessage()], 500);
        }
    }
}
