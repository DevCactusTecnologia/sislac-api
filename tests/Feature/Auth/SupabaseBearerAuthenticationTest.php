<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Route::middleware('supabase.auth')
        ->get('/_test/supabase-auth', function (Request $request) {
            $user = $request->user();

            return response()->json([
                'id' => $user?->getAuthIdentifier(),
                'email' => $user?->email,
            ]);
        });
});

it('autentica bearer token válido no Supabase Auth e correlaciona usuário central existente', function () {
    $user = User::factory()->create([
        'email' => 'ana@example.test',
    ]);

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $user->id,
            'email' => $user->email,
        ], 200),
    ]);

    $this->withToken('valid-token')
        ->getJson('/_test/supabase-auth')
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'email' => $user->email,
        ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://example.supabase.co/auth/v1/user'
        && $request->hasHeader('Authorization', 'Bearer valid-token')
        && $request->hasHeader('apikey', 'test-publishable-key'));
});

it('nega bearer token rejeitado pelo Supabase Auth', function () {
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'message' => 'invalid token',
        ], 401),
    ]);

    $this->withToken('invalid-token')
        ->getJson('/_test/supabase-auth')
        ->assertUnauthorized();
});

it('não provisiona usuário automaticamente quando o token é válido mas o UUID não existe no central', function () {
    $supabaseUserId = (string) Str::uuid();

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $supabaseUserId,
            'email' => 'novo@example.test',
        ], 200),
    ]);

    $this->withToken('valid-token')
        ->getJson('/_test/supabase-auth')
        ->assertForbidden();

    expect(User::query()->find($supabaseUserId))->toBeNull();
});

it('retorna indisponibilidade sem expor o token quando o Supabase Auth falha', function () {
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'message' => 'upstream failure',
        ], 500),
    ]);

    $response = $this->withToken('sensitive-token')
        ->getJson('/_test/supabase-auth');

    $response->assertStatus(503)
        ->assertJson([
            'message' => 'Serviço de autenticação indisponível.',
        ]);

    expect($response->getContent())->not->toContain('sensitive-token');
});

it('nega requisição sem bearer token', function () {
    $this->getJson('/_test/supabase-auth')->assertUnauthorized();
});
