<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contas do escritório nas plataformas de assinatura (ClickSign, D4Sign). Uma por plataforma.
        Schema::create('signature_providers', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 20)->unique(); // clicksign | d4sign
            $table->string('environment', 10)->default('sandbox'); // sandbox | producao
            $table->text('api_token')->nullable(); // criptografado
            $table->text('crypt_key')->nullable(); // criptografado (D4Sign)
            $table->string('safe_uuid', 64)->nullable(); // cofre da D4Sign
            $table->string('safe_name')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });

        // Documentos enviados para assinatura.
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20); // clicksign | d4sign | manual
            $table->string('title');
            $table->text('message')->nullable();
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('enviado'); // enviado | assinado | recusado | cancelado | expirado | erro
            $table->boolean('icp_brasil')->default(false);
            $table->date('deadline_at')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('signed_path')->nullable();
            $table->string('external_id', 100)->nullable()->index(); // envelope (ClickSign) ou documento (D4Sign)
            $table->string('external_document_id', 100)->nullable()->index(); // documento dentro do envelope (ClickSign)
            $table->string('cloud_file_url', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('document', 20)->nullable(); // CPF
            $table->string('role', 20)->default('parte'); // parte | advogado | testemunha
            $table->string('auth', 20)->default('email'); // email | sms | whatsapp
            $table->string('external_id', 100)->nullable();
            $table->string('status', 20)->default('pendente'); // pendente | assinado | recusado
            $table->timestamp('signed_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_requests');
        Schema::dropIfExists('signature_providers');
    }
};
