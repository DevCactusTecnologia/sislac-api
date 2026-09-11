<?php

namespace App\Platform\Supabase;

use Illuminate\Auth\GenericUser;

final class SupabaseAuthUser extends GenericUser
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $email,
    ) {
        parent::__construct([
            'id' => $id,
            'email' => $email,
            'name' => $email,
            'password' => '',
            'remember_token' => null,
        ]);
    }

    public function getKey(): string
    {
        return (string) $this->getAuthIdentifier();
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
