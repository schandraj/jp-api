<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Display a listing of users with pagination and custom limit.
     */
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $limit = $request->input('limit', 10);
            $users = User::query()
                ->when($request->has('role'), function ($query) use ($request) {
                    $query->where('role', $request->role);
                })
                ->when($request->has('search'), function ($query) use ($request) {
                    $query->where('fullname', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                })
                ->paginate($limit);

            return response()->json([
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve users: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        try {
            $user = User::findOrFail($id);
            return response()->json([
                'message' => 'User retrieved successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fullname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone_number' => 'required|string|max:15|unique:users',
            'role' => 'required|string|in:user,admin',
            'nik' => 'nullable|string|size:16|unique:users,nik',
            'address' => 'nullable|string',
            'profession' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $profilePicturePath = null;
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('profile_pictures', 'public');
            }

            $user = User::create([
                'fullname' => $request->fullname,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'role' => $request->role,
                'nik' => $request->nik,
                'address' => $request->address,
                'profession' => $request->profession,
                'bio' => $request->bio,
                'profile_picture' => $profilePicturePath,
                'password' => Hash::make($request->password),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'User created successfully',
                'data' => $user
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($profilePicturePath) {
                Storage::disk('public')->delete($profilePicturePath);
            }
            return response()->json(['error' => 'Failed to create user: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'fullname' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
                'phone_number' => 'sometimes|required|string|max:15|unique:users,phone_number,' . $user->id,
                'role' => 'sometimes|required|string|in:user,admin',
                'nik' => 'nullable|string|size:16|unique:users,nik,' . $user->id,
                'address' => 'nullable|string',
                'profession' => 'nullable|string|max:255',
                'bio' => 'nullable|string',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'password' => 'nullable|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            DB::beginTransaction();

            $profilePicturePath = $user->profile_picture;
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('profile_pictures', 'public');
                if ($user->profile_picture && $user->profile_picture !== $profilePicturePath) {
                    Storage::disk('public')->delete($user->profile_picture);
                }
            }

            $user->update([
                'fullname' => $request->fullname ?? $user->fullname,
                'email' => $request->email ?? $user->email,
                'phone_number' => $request->phone_number ?? $user->phone_number,
                'role' => $request->role ?? $user->role,
                'nik' => $request->nik ?? $user->nik,
                'address' => $request->address ?? $user->address,
                'profession' => $request->profession ?? $user->profession,
                'bio' => $request->bio ?? $user->bio,
                'profile_picture' => $profilePicturePath,
                'password' => $request->filled('password') ? Hash::make($request->password) : $user->password,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'User updated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($profilePicturePath) && $profilePicturePath !== $user->profile_picture) {
                Storage::disk('public')->delete($profilePicturePath);
            }
            return response()->json(['error' => 'Failed to update user: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a user.
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $profilePicturePath = $user->profile_picture;

            $user->delete();

            if ($profilePicturePath) {
                Storage::disk('public')->delete($profilePicturePath);
            }

            return response()->json(['message' => 'User deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete user: ' . $e->getMessage()], 500);
        }
    }
}

