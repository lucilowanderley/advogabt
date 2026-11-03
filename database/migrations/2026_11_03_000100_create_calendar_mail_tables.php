<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links de assinatura da agenda (.ics) para Google Agenda, Outlook, iPhone...
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->string('scope', 10)->default('meus'); // meus | equipe
            $table->string('tasks', 10)->default('prazos'); // prazos | todas | nenhuma
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });

        // Envio de e-mails do escritório (Gmail, Outlook ou outro SMTP com senha de app).
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 10)->default('gmail'); // gmail | outlook | smtp
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 5)->default('tls'); // tls | ssl | none
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // criptografada
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('send_invites')->default(true); // convite (.ics) ao responsável quando o compromisso é marcado/alterado
            $table->boolean('invite_clients')->default(false); // convite também ao cliente vinculado (audiência, reunião, perícia)
            $table->boolean('remind_events')->default(true);
            $table->unsignedSmallInteger('remind_hours')->default(24);
            $table->boolean('remind_clients')->default(false);
            $table->boolean('daily_digest')->default(true); // resumo diário de prazos e compromissos para cada responsável
            $table->unsignedTinyInteger('digest_hour')->default(7);
            $table->date('last_digest_on')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // convite | cancelamento | lembrete | resumo | teste
            $table->string('to');
            $table->string('subject');
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0); // versão do convite (.ics) enviado
            $table->text('invited_emails')->nullable(); // quem recebeu o último convite (para cancelar se trocar o responsável)
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['sequence', 'invited_emails', 'reminded_at']);
        });
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('mail_settings');
        Schema::dropIfExists('calendar_feeds');
    }
};
