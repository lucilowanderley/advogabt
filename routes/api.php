<?php

use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WebhookSubscriptionController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 do ADVOGABT (prefixo /api/v1). Autenticação por token criado em
| Integrações > API REST: "Authorization: Bearer advbt_...". Documentação em /integracoes/api.
*/
// Avisos de mensagens do WhatsApp (Evolution API ou Meta). O token na URL identifica o canal.
Route::get('/whatsapp/{token}', [WhatsappWebhookController::class, 'verify'])->middleware('throttle:60,1')->name('whatsapp.webhook.verify');
Route::post('/whatsapp/{token}', [WhatsappWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('whatsapp.webhook');

Route::prefix('v1')->name('api.')->middleware(['api.token', 'throttle:api-token'])->group(function () {
    Route::get('/status', [StatusController::class, 'status'])->name('status');
    Route::get('/eventos', [StatusController::class, 'events'])->name('events');

    Route::get('/processos', [CaseController::class, 'index'])->name('cases.index');
    Route::get('/processos/{legal_case}', [CaseController::class, 'show'])->name('cases.show');
    Route::get('/contatos', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contatos/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::get('/tarefas', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('/tarefas/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('/prazos', [TaskController::class, 'deadlines'])->name('deadlines');
    Route::get('/agenda', [EventController::class, 'index'])->name('events.index');
    Route::get('/financeiro', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/financeiro/{entry}', [FinanceController::class, 'show'])->name('finance.show');
    Route::get('/webhooks', [WebhookSubscriptionController::class, 'index'])->name('webhooks.index');

    Route::middleware('api.token:escrita')->group(function () {
        Route::post('/processos', [CaseController::class, 'store'])->name('cases.store');
        Route::patch('/processos/{legal_case}', [CaseController::class, 'update'])->name('cases.update');
        Route::post('/processos/{legal_case}/andamentos', [CaseController::class, 'storeMovement'])->name('cases.movements.store');
        Route::post('/contatos', [ContactController::class, 'store'])->name('contacts.store');
        Route::patch('/contatos/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::post('/tarefas', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tarefas/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/agenda', [EventController::class, 'store'])->name('events.store');
        Route::post('/financeiro', [FinanceController::class, 'store'])->name('finance.store');
        Route::post('/financeiro/{entry}/baixa', [FinanceController::class, 'pay'])->name('finance.pay');
        Route::post('/webhooks', [WebhookSubscriptionController::class, 'store'])->name('webhooks.store');
        Route::delete('/webhooks/{webhook}', [WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy');
    });

    Route::fallback(fn () => response()->json(['message' => 'Rota não encontrada na API v1. Veja a documentação em '.url('/integracoes/api')], 404));
});
