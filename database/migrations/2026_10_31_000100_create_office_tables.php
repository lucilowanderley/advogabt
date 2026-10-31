<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('person_type', 2)->default('PF'); // PF | PJ
            $table->string('category', 30)->default('cliente'); // cliente, parte_contraria, testemunha, outro
            $table->string('crm_stage', 30)->default('cliente'); // lead, contato, proposta, cliente, perdido
            $table->string('document', 20)->nullable(); // CPF / CNPJ
            $table->string('rg', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('profession')->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('zip_code', 10)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('origin')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'crm_stage']);
            $table->index('name');
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 30)->default('correspondente'); // correspondente, indicador, perito, escritorio, outro
            $table->string('oab', 30)->nullable();
            $table->string('document', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->string('pix_key')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_cases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable(); // número CNJ
            $table->string('title');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_role', 20)->default('autor'); // autor, reu, terceiro
            $table->string('opposing_party')->nullable();
            $table->string('area', 40)->default('civel');
            $table->string('action_type')->nullable();
            $table->string('court')->nullable(); // tribunal
            $table->string('court_division')->nullable(); // vara
            $table->string('district')->nullable(); // comarca
            $table->string('phase', 30)->default('inicial'); // inicial, conhecimento, recursal, execucao, encerrado
            $table->string('status', 20)->default('ativo'); // ativo, suspenso, arquivado, encerrado
            $table->decimal('claim_value', 14, 2)->nullable();
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->date('distributed_at')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('case_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->text('description');
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente, fazendo, concluida
            $table->string('priority', 10)->default('normal'); // baixa, normal, alta, urgente
            $table->string('group')->nullable(); // equipe / projeto
            $table->dateTime('due_at')->nullable();
            $table->boolean('is_deadline')->default(false); // prazo processual
            $table->unsignedSmallInteger('points')->default(10);
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('legal_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20)->default('reuniao'); // audiencia, reuniao, prazo, pericia, outro
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->foreignId('legal_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('starts_at');
        });

        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // receita, despesa
            $table->string('description');
            $table->string('category', 40)->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['type', 'due_date']);
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 40)->default('outro'); // procuracao, contrato, peticao, declaracao, outro
            $table->longText('content');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('financial_entries');
        Schema::dropIfExists('events');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_user');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('case_movements');
        Schema::dropIfExists('legal_cases');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('contacts');
    }
};
