<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Armazenamento na nuvem do escritório (Google Drive ou OneDrive), conectado por OAuth.
        Schema::create('cloud_storages', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 20); // google | onedrive
            $table->string('client_id')->nullable(); // app do escritório no Google Cloud / Microsoft Entra
            $table->text('client_secret')->nullable(); // criptografado
            $table->string('tenant', 100)->nullable(); // Microsoft: common | organizations | consumers | id do locatário
            $table->string('access', 10)->default('completo'); // Google: completo (drive) | app (drive.file)
            $table->string('redirect_uri')->nullable();
            $table->text('access_token')->nullable(); // criptografado
            $table->text('refresh_token')->nullable(); // criptografado
            $table->timestamp('token_expires_at')->nullable();
            $table->string('oauth_state', 64)->nullable();
            $table->string('return_url')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_email')->nullable();
            $table->string('drive_id')->nullable(); // OneDrive
            $table->string('root_name')->default('ADVOGABT');
            $table->string('root_folder_id')->nullable();
            $table->string('root_folder_url', 500)->nullable();
            $table->json('folder_cache')->nullable(); // ids das pastas "Clientes" e "Processos"
            $table->string('case_layout', 10)->default('cliente'); // cliente | separadas
            $table->text('subfolders')->nullable(); // subpastas criadas em cada processo (uma por linha)
            $table->boolean('auto_create')->default(true);
            $table->boolean('copy_whatsapp')->default(true);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Pasta da nuvem vinculada a um processo ou a um contato (cliente).
        Schema::create('cloud_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_storage_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 10); // processo | contato
            $table->unsignedBigInteger('owner_id');
            $table->string('remote_id');
            $table->string('name');
            $table->string('web_url', 500)->nullable();
            $table->boolean('linked')->default(false); // pasta já existente, vinculada à mão
            $table->timestamps();
            $table->unique(['cloud_storage_id', 'owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_folders');
        Schema::dropIfExists('cloud_storages');
    }
};
