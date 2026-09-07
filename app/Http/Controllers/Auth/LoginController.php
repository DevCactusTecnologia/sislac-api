<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Platform\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $key = $this->rateLimitKey($request);
        $attempts = RateLimiter::increment($key, decaySeconds: self::DECAY_SECONDS);

        if ($attempts > self::MAX_ATTEMPTS) {
            return response()
                ->json(['message' => 'Muitas tentativas. Tente novamente em instantes.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    private function rateLimitKey(LoginRequest $request): string
    {
        $identity = $request->string('email')->toString().'|'.($request->ip() ?? 'unknown');

        return 'login:'.hash('sha256', $identity);
    }
}
