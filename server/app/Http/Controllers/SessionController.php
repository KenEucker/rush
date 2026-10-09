<?php

namespace App\Http\Controllers;

use App\Services\Identity\SessionIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(Request $request, SessionIdentity $identity)
    {
        abort_if(Auth::check(), 409, 'Sign out before signing in to another account.');
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if (! Auth::attemptWhen($credentials, fn ($user) => $user->organizationMemberships()
            ->where('is_active', true)->exists())) {
            throw ValidationException::withMessages(['email' => 'The provided credentials could not be verified.']);
        }

        $request->session()->regenerate();

        return response()->json($identity->forUser($request->user()));
    }

    public function show(Request $request, SessionIdentity $identity)
    {
        return response()->json($identity->forUser($request->user()));
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->noContent() : redirect('/sign-in');
    }
}
