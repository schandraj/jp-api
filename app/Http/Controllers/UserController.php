<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $limit = min((int) $request->query('limit', 10), 100);

        $users = User::where('username', '!=', 'admin')
            ->paginate($limit)
            ->appends($request->query());

        return response()->json($users);
    }

}
