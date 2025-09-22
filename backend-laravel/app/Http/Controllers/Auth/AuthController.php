<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login handler.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        // Validation
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ]);

        $login = $request->login;
        $password = $request->password;

        // Find User
        $user = User::where('username', $login)
            ->orWhere('email', $login)
            ->first();

        // User Authentication
        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
                'password' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Regenerate session to prevent fixation
        $request->session()->regenerate();

        return response()->json(Auth::user());
    }

    /**
     * Logout handler.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        // Invalidate the session
        $request->session()->invalidate();

        // Regenerate CSRF token
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Get aunhenticated user info.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}