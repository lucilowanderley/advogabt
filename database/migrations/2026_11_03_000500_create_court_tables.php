<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Integração com os tribunais: andamentos pelo Datajud (CNJ) e intimações/publicações pelo DJEN. */
return new class extends Migration
{
    public function up(): void
    {
        // Configuração do escritório (uma linha).
        Schema::create('court_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('datajud_enabled')->default(true);
            $table->text('datajud_api_key')->nullable(); // criptografada; vazia = chave pública do CNJ
            $table->unsignedSmallInteger('datajud_hours')->default(24); // intervalo entre consultas de cada processo
            $table->boolean('datajud_notify')->default(true); // novos andamentos disparam avisos (webhook/WhatsApp)
            $table->boolean('djen_enabled')->default(true);
            $table->boolean('auto_deadline')->default(true); // cria o prazo sozinho quando a publicação é de um processo cadastrado
            $table->unsignedSmallInteger('deadline_days')->default(15); // prazo padrão em dias úteis
            $table->text('holidays')->nullable(); // feriados locais, um por linha (dd/mm ou dd/mm/aaaa)
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        // OABs acompanhadas no DJEN.
        Schema::create('court_lawyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('oab_number', 12);
            $table->string('oab_uf', 2);
            $table->boolean('active')->default(true);
            $table->date('synced_until')->nullable(); // último dia de disponibilização já consultado
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['oab_number', 'oab_uf']);
        });

        // Intimações e publicações capturadas no DJEN.
        Schema::create('court_publications', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->unique(); // id da comunicação no DJEN
            $table->foreignId('court_lawyer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('process_number', 20)->nullable()->index(); // só dígitos (CNJ)
            $table->string('court', 20)->nullable(); // sigla do tribunal
            $table->string('kind', 60)->nullable(); // Intimação, Edital, Citação...
            $table->string('document_type', 100)->nullable(); // Despacho, Decisão, Sentença...
            $table->string('organ')->nullable(); // vara / órgão
            $table->string('class_name')->nullable();
            $table->longText('text')->nullable();
            $table->string('link', 500)->nullable();
            $table->json('parties')->nullable();
            $table->json('lawyers')->nullable();
            $table->date('available_at')->index(); // data de disponibilização no diário
            $table->date('published_at')->nullable(); // 1º dia útil seguinte (Lei 11.419/2006, art. 4º, § 3º)
            $table->unsignedSmallInteger('deadline_days')->nullable(); // prazo detectado no texto
            $table->string('status', 20)->default('nova'); // nova | tratada | descartada
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });

        Schema::table('legal_cases', function (Blueprint $table) {
            $table->boolean('datajud_sync')->default(true);
            $table->timestamp('datajud_checked_at')->nullable();
            $table->timestamp('datajud_updated_at')->nullable(); // última atualização do processo na base do CNJ
            $table->text('datajud_error')->nullable();
        });

        Schema::table('case_movements', function (Blueprint $table) {
            $table->string('source', 20)->default('manual'); // manual | datajud | djen
            $table->string('external_id', 64)->nullable();
            $table->unique(['legal_case_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('case_movements', function (Blueprint $table) {
            $table->dropUnique(['legal_case_id', 'external_id']);
            $table->dropColumn(['source', 'external_id']);
        });
        Schema::table('legal_cases', function (Blueprint $table) {
            $table->dropColumn(['datajud_sync', 'datajud_checked_at', 'datajud_updated_at', 'datajud_error']);
        });
        Schema::dropIfExists('court_publications');
        Schema::dropIfExists('court_lawyers');
        Schema::dropIfExists('court_settings');
    }
};
