<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('laboratories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('provisioning')->index();
            $table->text('database_url')->nullable();
            $table->timestampsTz();
        });

        Schema::connection('central')->table('users', function (Blueprint $table): void {
            $table->foreignUuid('laboratory_id')
                ->nullable()
                ->constrained('laboratories')
                ->restrictOnDelete();
            $table->string('role')->nullable();
            $table->string('status')->default('active');
            $table->jsonb('permissions_extra')->nullable();
            $table->jsonb('permissions_revoked')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('laboratory_id');
            $table->dropColumn([
                'role',
                'status',
                'permissions_extra',
                'permissions_revoked',
            ]);
        });

        Schema::connection('central')->dropIfExists('laboratories');
    }
};
