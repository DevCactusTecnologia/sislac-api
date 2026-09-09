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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('provisioning')->index();
            $table->string('database_name')->unique();
            $table->timestampsTz();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->unique(['user_id', 'tenant_id']);
            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status')->index();
            $table->string('schema_version')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('platform_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->string('subject_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit');
        Schema::dropIfExists('provisioning_runs');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('tenants');
    }
};
