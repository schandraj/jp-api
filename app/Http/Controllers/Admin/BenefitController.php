<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Benefit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BenefitController extends Controller
{
    /**
     * Display a listing of benefits.
     */
    public function index(Request $request)
    {
        // Validate the limit parameter
        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Set default limit if not provided`
        $limit = $request->input('limit', 10);
        $benefits = Benefit::paginate($limit);
        return response()->json([
            'message' => 'Benefits retrieved successfully',
            'data' => $benefits
        ], 200);
    }

    /**
     * Store a newly created benefit.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:benefits,name',
            'icon' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $benefit = Benefit::create([
                'name' => $request->name,
                'icon' => $request->icon,
            ]);

            return response()->json([
                'message' => 'Benefit created successfully',
                'data' => $benefit
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create benefit'], 500);
        }
    }

    /**
     * Display the specified benefit.
     */
    public function show($id)
    {
        try {
            $benefit = Benefit::findOrFail($id);
            return response()->json([
                'message' => 'Benefit retrieved successfully',
                'data' => $benefit
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Benefit not found'], 404);
        }
    }

    /**
     * Update the specified benefit.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:benefits,name,' . $id,
            'icon' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $benefit = Benefit::findOrFail($id);
            $benefit->update([
                'name' => $request->name,
                'icon' => $request->icon,
            ]);

            return response()->json([
                'message' => 'Benefit updated successfully',
                'data' => $benefit
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update benefit'], 500);
        }
    }

    /**
     * Remove the specified benefit.
     */
    public function destroy($id)
    {
        try {
            $benefit = Benefit::findOrFail($id);
            $benefit->delete();
            return response()->json(['message' => 'Benefit deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete benefit'], 500);
        }
    }
}
