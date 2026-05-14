<?php

namespace App\Http\Controllers;

use App\Services\UnipinService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    // Web form login
    public function login(Request $request, UnipinService $unipin)
    {
        $result = $unipin->login($request->username, $request->password);

        if ($result['success']) {
            return redirect()->route('dashboard');
        }

        return back()->withErrors(['login' => $result['message']]);
    }

    // API login → return JSON
    public function apiLogin(Request $request, UnipinService $unipin)
    {
        $email    = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            return response()->json([
                'success' => false,
                'message' => 'Email dan password wajib diisi',
            ], 422);
        }

        $result = $unipin->login($email, $password);

        return response()->json($result);
    }

    public function logout()
    {
        session()->forget(['unipin_cookies', 'unipin_user']);
        return redirect()->route('login');
    }
}