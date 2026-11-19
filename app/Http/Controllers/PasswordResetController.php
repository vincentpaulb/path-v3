<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function sendLink(Request $request)
    {
        $request->validateWithBag('forgot', ['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return back()->with('password_reset_status', 'If an active PATH account uses that email address, a reset link has been sent.');
    }

    public function show(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('public.proposals', ['login' => 1])->with('password_reset_status', 'Password reset successfully. You can now sign in.')
            : back()->withErrors(['email' => __($status)])->withInput($request->only('email'));
    }
}
