<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Platform\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthenticateClinicalUserController
{
    public function __invoke(LoginRequest $request): Response
    {
        $authenticated = Auth::attempt([
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        $user = Auth::user();
        $laboratory = $user instanceof User ? $user->laboratory : null;

        if ($laboratory === null || $laboratory->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        $request->session()->regenerate();

        return response()->noContent();
    }
}
