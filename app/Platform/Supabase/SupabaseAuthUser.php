<?php

namespace App\Platform\Supabase;

final readonly class SupabaseAuthUser
{
    public function __construct(
        public string $id,
        public ?string $email,
    ) {}
}
