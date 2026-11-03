<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contas do escritório nos bancos/plataformas de cobrança (Asaas, Banco Inter). Uma por plataforma.
        Schema::create('billing_providers', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 20)->unique(); // asaas | inter
            $table->string('environment', 10)->default('sandbox'); // sandbox | producao
            $table->text('api_key')->nullable(); // criptografado (Asaas)
            $table->string('client_id')->nullable(); // Banco Inter
            $table->text('client_secret')->nullable(); // criptografado (Banco Inter)
            $table->text('certificate')->nullable(); // criptografado: .crt do Banco Inter
            $table->text('private_key')->nullable(); // criptografado: .key do Banco Inter
            $table->string('account_number', 30)->nullable(); // conta corrente (Banco Inter, opcional)
            $table->decimal('fine_percent', 5, 2)->default(2); // multa por atraso (%)
            $table->decimal('interest_percent', 5, 2)->default(1); // juros ao mês (%)
            $table->boolean('notify_customer')->default(true); // Asaas avisa o cliente por e-mail/SMS
            $table->json('settings')->nullable(); // NFS-e (Asaas) e outras opções
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });

        // Boletos e Pix gerados para os lançamentos a receber.
        Schema::create('billing_charges', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20); // asaas | inter
            $table->string('method', 20)->default('boleto_pix'); // boleto_pix | pix | livre
            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('pendente'); // pendente | pago | vencido | cancelado | estornado | erro
            $table->string('external_id', 100)->nullable()->index(); // id do pagamento (Asaas) ou codigoSolicitacao (Inter)
            $table->string('external_customer_id', 100)->nullable();
            $table->string('remote_status', 40)->nullable();
            $table->string('payment_url', 500)->nullable(); // fatura / link de pagamento
            $table->string('slip_url', 500)->nullable(); // PDF do boleto (Asaas)
            $table->string('digitable_line', 80)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->text('pix_payload')->nullable(); // Pix copia e cola
            $table->mediumText('pix_qr')->nullable(); // imagem PNG em base64 (Asaas)
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->string('paid_with', 20)->nullable(); // boleto | pix | cartao | outro
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        // Notas fiscais de serviço (NFS-e) emitidas pelo Asaas.
        Schema::create('fiscal_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('asaas');
            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->text('service_description');
            $table->text('observations')->nullable();
            $table->string('status', 20)->default('processando'); // processando | emitida | cancelada | erro
            $table->string('external_id', 100)->nullable()->index();
            $table->string('remote_status', 40)->nullable();
            $table->string('number', 40)->nullable();
            $table->string('verification_code', 80)->nullable();
            $table->string('pdf_url', 500)->nullable();
            $table->string('xml_url', 500)->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_invoices');
        Schema::dropIfExists('billing_charges');
        Schema::dropIfExists('billing_providers');
    }
};
