<?php

namespace App\Platform\Supabase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class SupabaseAuth
{
    public function user(string $accessToken): ?SupabaseAuthUser
    {
        $url = config('services.supabase.url');
        $publishableKey = config('services.supabase.publishable_key');

        if (! is_string($url) || $url === '' || ! is_string($publishableKey) || $publishableKey === '') {
            throw new RuntimeException('Configuração do Supabase Auth ausente.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['apikey' => $publishableKey])
                ->withToken($accessToken)
                ->timeout(5)
                ->get(rtrim($url, '/').'/auth/v1/user');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Supabase Auth indisponível.', previous: $exception);
        }

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Supabase Auth indisponível.');
        }

        $payload = $response->json();
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        $email = is_array($payload) ? ($payload['email'] ?? null) : null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new RuntimeException('Resposta inválida do Supabase Auth.');
        }

        return new SupabaseAuthUser(
            id: $id,
            email: is_string($email) ? $email : null,
        );
    }
}
