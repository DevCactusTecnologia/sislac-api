<?php

namespace App\Platform\Supabase;

use Illuminate\Auth\GenericUser;

final class SupabasePrincipal extends GenericUser
{
    public function __construct(string $id, ?string $email)
    {
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
