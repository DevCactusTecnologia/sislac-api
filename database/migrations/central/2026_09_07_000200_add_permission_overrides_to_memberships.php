<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        DB::connection('central')->statement(<<<'SQL'
            ALTER TABLE memberships
                ADD COLUMN permissions_extra jsonb NOT NULL DEFAULT '[]'::jsonb,
                ADD COLUMN permissions_revoked jsonb NOT NULL DEFAULT '[]'::jsonb,
                ADD CONSTRAINT memberships_permissions_extra_array_check
                    CHECK (jsonb_typeof(permissions_extra) = 'array'),
                ADD CONSTRAINT memberships_permissions_revoked_array_check
                    CHECK (jsonb_typeof(permissions_revoked) = 'array')
        SQL);
    }

    public function down(): void
    {
        DB::connection('central')->statement(<<<'SQL'
            ALTER TABLE memberships
                DROP CONSTRAINT IF EXISTS memberships_permissions_extra_array_check,
                DROP CONSTRAINT IF EXISTS memberships_permissions_revoked_array_check,
                DROP COLUMN IF EXISTS permissions_extra,
                DROP COLUMN IF EXISTS permissions_revoked
        SQL);
    }
};
