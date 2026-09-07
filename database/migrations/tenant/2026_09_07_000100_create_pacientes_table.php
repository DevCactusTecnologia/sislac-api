<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('nome');
            $table->text('nome_social')->nullable();
            $table->text('cpf')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->text('sexo')->default('M');
            $table->text('telefone')->default('');
            $table->text('celular')->default('');
            $table->text('email')->default('');
            $table->text('cep')->default('');
            $table->text('estado')->default('');
            $table->text('cidade')->default('');
            $table->text('bairro')->default('');
            $table->text('endereco')->default('');
            $table->text('numero')->default('');
            $table->text('complemento')->default('');
            $table->text('status')->default('Ativo');
            $table->text('guardian_name')->nullable();
            $table->text('guardian_cpf')->nullable();
            $table->boolean('consentimento_lgpd')->default(false);
            $table->timestampTz('consentimento_em')->nullable();
            $table->text('friendly_id')->default('');
            $table->timestampsTz();

            $table->index('cpf', 'idx_pacientes_cpf');
            $table->index('nome', 'idx_pacientes_nome');
            $table->index('status', 'idx_pacientes_status');
            $table->index(['updated_at', 'id'], 'idx_pacientes_cursor');
        });

        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT pacientes_sexo_check CHECK (sexo IN ('M', 'F'))");
        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT pacientes_status_check CHECK (status IN ('Ativo', 'Inativo'))");
        DB::statement("CREATE UNIQUE INDEX pacientes_cpf_unique_nonempty ON pacientes (cpf) WHERE cpf IS NOT NULL AND cpf <> ''");
        DB::statement("CREATE UNIQUE INDEX pacientes_friendly_id_unique_nonempty ON pacientes (friendly_id) WHERE friendly_id <> ''");
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
