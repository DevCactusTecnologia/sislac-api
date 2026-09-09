<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_config', function (Blueprint $table): void {
            $table->smallInteger('singleton_key')->primary();
            $table->text('rotina_fluxo_modo')->default('completo');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE lab_config ADD CONSTRAINT lab_config_singleton_check CHECK (singleton_key = 1)');
        DB::statement("ALTER TABLE lab_config ADD CONSTRAINT lab_config_rotina_fluxo_modo_check CHECK (rotina_fluxo_modo IN ('completo', 'coleta_resultado', 'apenas_resultado'))");

        DB::table('lab_config')->insert([
            'singleton_key' => 1,
            'rotina_fluxo_modo' => 'completo',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_config');
    }
};
