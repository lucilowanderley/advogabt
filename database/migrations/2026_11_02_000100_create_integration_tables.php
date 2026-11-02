<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tokens de acesso à API REST (n8n, Make, Zapier, bot de WhatsApp, sistemas próprios).
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique(); // sha256 do token; o token em si nunca é gravado
            $table->string('prefix', 16); // início do token, para reconhecê-lo na lista
            $table->string('access', 10)->default('leitura'); // leitura | escrita
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quem criou (autor dos registros via API)
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Webhooks: o ADVOGABT avisa uma URL externa quando algo acontece.
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 500);
            $table->text('secret'); // criptografado; assina cada envio (HMAC SHA-256)
            $table->json('events'); // lista de eventos assinados; ["*"] = todos
            $table->boolean('active')->default(true);
            $table->string('source', 20)->default('painel'); // painel | api (assinado via API por n8n/Make/Zapier)
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_delivery_at')->nullable();
            $table->boolean('last_delivery_ok')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('event', 40);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_id', 'created_at']);
            $table->index(['success', 'attempts']);
        });

        // Evita avisar o mesmo prazo duas vezes (zera quando o vencimento muda).
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('deadline_alerted_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('deadline_alerted_at');
        });
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_tokens');
    }
};
