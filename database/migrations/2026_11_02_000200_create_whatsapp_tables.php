<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Número de WhatsApp do escritório, conectado pela Evolution API ou pela API oficial da Meta.
        Schema::create('whatsapp_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 20); // evolution | meta
            $table->string('base_url')->nullable(); // Evolution: URL do servidor
            $table->string('instance')->nullable(); // Evolution: nome da instância
            $table->text('api_key')->nullable(); // Evolution: apikey / Meta: token de acesso (criptografado)
            $table->string('phone_number_id')->nullable(); // Meta
            $table->text('app_secret')->nullable(); // Meta: confere a assinatura dos avisos (criptografado)
            $table->string('verify_token', 64)->nullable(); // Meta: verificação do webhook
            $table->string('webhook_token', 64)->unique(); // parte secreta da URL que recebe as mensagens
            $table->string('display_phone', 30)->nullable();
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->boolean('bot_enabled')->default(true);
            $table->boolean('notify_movements')->default(false); // avisa o cliente de novos andamentos
            $table->text('greeting')->nullable();
            $table->text('instructions')->nullable(); // orientações extras para a IA
            $table->json('team_user_ids')->nullable(); // quem recebe as tarefas de atendimento
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_channel_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 30); // só dígitos, com DDI
            $table->string('profile_name')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10)->default('bot'); // bot | humano
            $table->string('state', 30)->nullable(); // etapa do menu do bot
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable(); // janela de 24h da Meta
            $table->timestamps();
            $table->unique(['whatsapp_channel_id', 'phone']);
            $table->index('last_message_at');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->string('sender', 10); // cliente | bot | equipe | sistema
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 15)->default('text'); // text | document | image | audio | video | other
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_name')->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('external_id')->nullable();
            $table->string('status', 10)->default('ok'); // ok | erro
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['whatsapp_conversation_id', 'id']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_channels');
    }
};
