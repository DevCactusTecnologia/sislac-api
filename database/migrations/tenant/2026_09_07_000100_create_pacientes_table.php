<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendly_id_counters', function (Blueprint $table): void {
            $table->text('scope')->primary();
            $table->unsignedBigInteger('next_value');
        });

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
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('cpf', 'idx_pacientes_cpf');
            $table->index('nome', 'idx_pacientes_nome');
            $table->index('status', 'idx_pacientes_status');
            $table->index(['updated_at', 'id'], 'idx_pacientes_cursor');
        });

        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT pacientes_sexo_check CHECK (sexo IN ('M', 'F'))");
        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT pacientes_status_check CHECK (status IN ('Ativo', 'Inativo'))");
        DB::statement("CREATE UNIQUE INDEX pacientes_cpf_unique_nonempty ON pacientes (cpf) WHERE cpf IS NOT NULL AND cpf <> ''");
        DB::statement("CREATE UNIQUE INDEX pacientes_friendly_id_unique_nonempty ON pacientes (friendly_id) WHERE friendly_id <> ''");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION block_paciente_friendly_id_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.friendly_id IS DISTINCT FROM OLD.friendly_id THEN
                    RAISE EXCEPTION 'friendly_id de paciente é imutável';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pacientes_block_friendly_id_update
            BEFORE UPDATE OF friendly_id ON pacientes
            FOR EACH ROW
            EXECUTE FUNCTION block_paciente_friendly_id_update();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS block_paciente_friendly_id_update() CASCADE');
        Schema::dropIfExists('pacientes');
        Schema::dropIfExists('friendly_id_counters');
    }
};
