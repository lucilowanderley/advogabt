<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LegalCaseController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/buscar', SearchController::class)->name('search');

    Route::patch('/tarefas/{task}/mover', [TaskController::class, 'move'])->name('tasks.move');
    Route::post('/tarefas/{task}/comentarios', [TaskController::class, 'comment'])->name('tasks.comment');
    Route::resource('tarefas', TaskController::class)->parameters(['tarefas' => 'task'])->names('tasks');

    Route::resource('agenda', EventController::class)->parameters(['agenda' => 'event'])->except('show')->names('events');

    Route::patch('/contatos/{contact}/etapa', [ContactController::class, 'moveStage'])->name('contacts.stage');
    Route::resource('contatos', ContactController::class)->parameters(['contatos' => 'contact'])->names('contacts');

    Route::post('/processos/{legal_case}/andamentos', [LegalCaseController::class, 'storeMovement'])->name('cases.movements.store');
    Route::delete('/processos/{legal_case}/andamentos/{movement}', [LegalCaseController::class, 'destroyMovement'])->name('cases.movements.destroy');
    Route::resource('processos', LegalCaseController::class)->parameters(['processos' => 'legal_case'])->names('cases');

    Route::resource('parceiros', PartnerController::class)->parameters(['parceiros' => 'partner'])->names('partners');

    Route::patch('/financeiro/{entry}/baixa', [FinancialEntryController::class, 'togglePaid'])->name('finance.toggle');
    Route::resource('financeiro', FinancialEntryController::class)->parameters(['financeiro' => 'entry'])->except('show')->names('finance');

    Route::get('/modelos/{template}/gerar', [DocumentTemplateController::class, 'generate'])->name('templates.generate');
    Route::resource('modelos', DocumentTemplateController::class)->parameters(['modelos' => 'template'])->names('templates');

    Route::get('/relatorios', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/relatorios/exportar', [ReportController::class, 'export'])->name('reports.export');

    Route::middleware('can:admin')->group(function () {
        Route::post('/assistente/provedores/modelos', [AiProviderController::class, 'models'])->name('ai-providers.models')->middleware('throttle:20,1');
        Route::post('/assistente/provedores/{ai_provider}/testar', [AiProviderController::class, 'test'])->name('ai-providers.test')->middleware('throttle:20,1');
        Route::patch('/assistente/provedores/{ai_provider}/padrao', [AiProviderController::class, 'makeDefault'])->name('ai-providers.default');
        Route::resource('assistente/provedores', AiProviderController::class)->parameters(['provedores' => 'ai_provider'])->except('show')->names('ai-providers');
    });
    Route::get('/assistente', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('/assistente', [AssistantController::class, 'store'])->name('assistant.store')->middleware('throttle:30,1');
    Route::get('/assistente/{conversation}', [AssistantController::class, 'show'])->whereNumber('conversation')->name('assistant.show');
    Route::patch('/assistente/{conversation}', [AssistantController::class, 'update'])->whereNumber('conversation')->name('assistant.update');
    Route::delete('/assistente/{conversation}', [AssistantController::class, 'destroy'])->whereNumber('conversation')->name('assistant.destroy');
    Route::post('/assistente/{conversation}/mensagens', [AssistantController::class, 'send'])->whereNumber('conversation')->name('assistant.send')->middleware('throttle:30,1');
    Route::post('/assistente/{conversation}/repetir', [AssistantController::class, 'retry'])->whereNumber('conversation')->name('assistant.retry')->middleware('throttle:30,1');
    Route::post('/assistente/respostas/{message}/modelo', [AssistantController::class, 'saveTemplate'])->name('assistant.template');

    Route::get('/integracoes', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/integracoes/api', [IntegrationController::class, 'docs'])->name('integrations.docs');
    Route::middleware('can:admin')->prefix('integracoes')->name('integrations.')->controller(IntegrationController::class)->group(function () {
        Route::get('/tokens', 'tokens')->name('tokens.index');
        Route::post('/tokens', 'storeToken')->name('tokens.store');
        Route::delete('/tokens/{api_token}', 'revokeToken')->name('tokens.destroy');

        Route::get('/webhooks', 'webhooks')->name('webhooks.index');
        Route::get('/webhooks/novo', 'createWebhook')->name('webhooks.create');
        Route::post('/webhooks', 'storeWebhook')->name('webhooks.store');
        Route::get('/webhooks/{webhook}', 'showWebhook')->name('webhooks.show');
        Route::get('/webhooks/{webhook}/editar', 'editWebhook')->name('webhooks.edit');
        Route::put('/webhooks/{webhook}', 'updateWebhook')->name('webhooks.update');
        Route::delete('/webhooks/{webhook}', 'destroyWebhook')->name('webhooks.destroy');
        Route::post('/webhooks/{webhook}/testar', 'testWebhook')->name('webhooks.test')->middleware('throttle:20,1');
        Route::post('/webhooks/{webhook}/segredo', 'rotateSecret')->name('webhooks.secret');
        Route::post('/entregas/{delivery}/reenviar', 'redeliver')->name('deliveries.redeliver')->middleware('throttle:20,1');
        Route::post('/rotina', 'runRoutine')->name('routine')->middleware('throttle:10,1');
    });

    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->except('show')->names('users')
        ->middleware('can:admin');
});
