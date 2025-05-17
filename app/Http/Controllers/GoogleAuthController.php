<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::firstOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'firstname' => $googleUser->getName(), // or parse first/last
            'username' => explode('@', $googleUser->getEmail())[0],
            'password' => Hash::make(Str::random(24)), // random password
            'role' => 'user',
        ]);

        $token = $user->createToken('google-token')->plainTextToken;

        return response()->json(['token' => $token]);
    }
}
