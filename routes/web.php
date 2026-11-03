<?php

use App\Http\Controllers\AgendaEmailController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingSettingsController;
use App\Http\Controllers\CloudFileController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CourtController;
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
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

// Arquivos na nuvem: retorno do login do Google/Microsoft (pode chegar por http://localhost no Laragon).
Route::get('/integracoes/arquivos/retorno', [CloudFileController::class, 'callback'])->name('cloud.callback')->middleware('throttle:20,1');

// Agenda e e-mail: link secreto de assinatura lido pelo Google Agenda, Outlook, iPhone...
Route::get('/calendario/{token}.ics', [AgendaEmailController::class, 'feed'])->where('token', '[A-Za-z0-9]{20,64}')
    ->name('calendar.feed')->middleware('throttle:60,1');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/buscar', SearchController::class)->name('search');

    Route::patch('/tarefas/{task}/mover', [TaskController::class, 'move'])->name('tasks.move');
    Route::post('/tarefas/{task}/comentarios', [TaskController::class, 'comment'])->name('tasks.comment');
    Route::resource('tarefas', TaskController::class)->parameters(['tarefas' => 'task'])->names('tasks');

    Route::get('/agenda/{event}/ics', [AgendaEmailController::class, 'eventIcs'])->whereNumber('event')->name('events.ics');
    Route::resource('agenda', EventController::class)->parameters(['agenda' => 'event'])->except('show')->names('events');

    Route::patch('/contatos/{contact}/etapa', [ContactController::class, 'moveStage'])->name('contacts.stage');
    Route::resource('contatos', ContactController::class)->parameters(['contatos' => 'contact'])->names('contacts');

    Route::post('/processos/{legal_case}/andamentos', [LegalCaseController::class, 'storeMovement'])->name('cases.movements.store');
    Route::delete('/processos/{legal_case}/andamentos/{movement}', [LegalCaseController::class, 'destroyMovement'])->name('cases.movements.destroy');
    Route::resource('processos', LegalCaseController::class)->parameters(['processos' => 'legal_case'])->names('cases');

    Route::resource('parceiros', PartnerController::class)->parameters(['parceiros' => 'partner'])->names('partners');

    Route::patch('/financeiro/{entry}/baixa', [FinancialEntryController::class, 'togglePaid'])->name('finance.toggle');
    Route::resource('financeiro', FinancialEntryController::class)->parameters(['financeiro' => 'entry'])->except('show')->names('finance');

    // Cobrança e notas fiscais: boletos/Pix e NFS-e dos lançamentos (equipe) e contas no Asaas/Banco Inter (administradores).
    Route::controller(BillingController::class)->prefix('cobrancas')->name('billing.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/nova', 'create')->name('create');
        Route::post('/', 'store')->name('store')->middleware('throttle:30,1');
        Route::get('/{charge}', 'show')->whereNumber('charge')->name('show');
        Route::post('/{charge}/atualizar', 'refresh')->whereNumber('charge')->name('refresh')->middleware('throttle:30,1');
        Route::post('/{charge}/cancelar', 'cancel')->whereNumber('charge')->name('cancel')->middleware('throttle:20,1');
        Route::get('/{charge}/boleto', 'pdf')->whereNumber('charge')->name('pdf')->middleware('throttle:30,1');
        Route::post('/retorno', 'importReturn')->name('return')->middleware('throttle:10,1');
        Route::get('/notas', 'invoices')->name('invoices');
        Route::post('/notas', 'storeInvoice')->name('invoices.store')->middleware('throttle:20,1');
        Route::post('/notas/{invoice}/atualizar', 'refreshInvoice')->whereNumber('invoice')->name('invoices.refresh')->middleware('throttle:30,1');
        Route::post('/notas/{invoice}/cancelar', 'cancelInvoice')->whereNumber('invoice')->name('invoices.cancel')->middleware('throttle:10,1');
    });
    Route::middleware('can:admin')->controller(BillingSettingsController::class)->prefix('integracoes/cobranca')->name('billing.')->group(function () {
        Route::get('/', 'settings')->name('settings');
        Route::post('/nfse', 'saveNfse')->name('nfse.save');
        Route::post('/nfse/servicos', 'services')->name('nfse.services')->middleware('throttle:20,1');
        Route::post('/nfse/testar', 'testNfse')->name('nfse.test')->middleware('throttle:20,1');
        Route::post('/inter/aviso', 'registerWebhook')->name('webhook.register')->middleware('throttle:10,1');
        Route::post('/{driver}', 'saveProvider')->where('driver', 'asaas|inter|bb|itau|bradesco|santander|sicoob|sicredi|caixa')->name('providers.save');
        Route::post('/{driver}/testar', 'testProvider')->where('driver', 'asaas|inter|bb|itau|bradesco|santander|sicoob|sicredi|caixa')->name('providers.test')->middleware('throttle:20,1');
        Route::delete('/{driver}', 'disconnect')->where('driver', 'asaas|inter|bb|itau|bradesco|santander|sicoob|sicredi|caixa')->name('providers.destroy');
    });

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

    Route::controller(AgendaEmailController::class)->prefix('integracoes/agenda-email')->name('agenda-email.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/links', 'storeFeed')->name('feeds.store');
        Route::post('/links/{feed}/trocar', 'regenerateFeed')->name('feeds.regenerate');
        Route::delete('/links/{feed}', 'destroyFeed')->name('feeds.destroy');
        Route::middleware('can:admin')->group(function () {
            Route::post('/email', 'saveMail')->name('mail.save');
            Route::post('/email/testar', 'testMail')->name('mail.test')->middleware('throttle:10,1');
            Route::post('/email/resumo', 'sendDigest')->name('mail.digest')->middleware('throttle:5,1');
        });
    });

    Route::middleware('can:admin')->prefix('integracoes/arquivos')->name('cloud.')->controller(CloudFileController::class)->group(function () {
        Route::get('/', 'settings')->name('settings');
        Route::post('/', 'save')->name('save');
        Route::get('/conectar', 'connect')->name('connect');
        Route::post('/token', 'pasteToken')->name('token')->middleware('throttle:20,1');
        Route::post('/rclone', 'installRclone')->name('rclone')->middleware('throttle:5,1');
        Route::post('/testar', 'test')->name('test')->middleware('throttle:20,1');
        Route::delete('/conexao', 'disconnect')->name('disconnect');
        Route::post('/pastas', 'createMissing')->name('missing')->middleware('throttle:10,1');
    });
    Route::controller(CloudFileController::class)->prefix('arquivos/{type}/{id}')->where(['type' => 'processo|contato', 'id' => '[0-9]+'])
        ->name('cloud.files.')->group(function () {
            Route::get('/', 'panel')->name('panel');
            Route::post('/', 'upload')->name('upload')->middleware('throttle:30,1');
            Route::post('/pasta', 'createFolder')->name('folder')->middleware('throttle:30,1');
            Route::post('/vincular', 'linkFolder')->name('link');
            Route::delete('/vinculo', 'unlinkFolder')->name('unlink');
            Route::delete('/itens/{file}', 'trash')->name('trash');
        });

    // Assinatura digital: documentos enviados (equipe) e plataformas (administradores).
    Route::controller(SignatureController::class)->prefix('assinaturas')->name('signatures.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/nova', 'create')->name('create');
        Route::post('/', 'store')->name('store')->middleware('throttle:20,1');
        Route::get('/{signature}', 'show')->whereNumber('signature')->name('show');
        Route::post('/{signature}/atualizar', 'refresh')->whereNumber('signature')->name('refresh')->middleware('throttle:30,1');
        Route::post('/{signature}/reenviar', 'resend')->whereNumber('signature')->name('resend')->middleware('throttle:10,1');
        Route::post('/{signature}/cancelar', 'cancel')->whereNumber('signature')->name('cancel');
        Route::post('/{signature}/assinado', 'attachSigned')->whereNumber('signature')->name('attach')->middleware('throttle:20,1');
        Route::get('/{signature}/arquivo/{kind}', 'download')->whereNumber('signature')->where('kind', 'original|assinado')->name('download');
        Route::delete('/{signature}', 'destroy')->whereNumber('signature')->name('destroy');
    });
    Route::middleware('can:admin')->controller(SignatureController::class)->prefix('integracoes/assinatura')->name('signatures.')->group(function () {
        Route::get('/', 'settings')->name('settings');
        Route::post('/cofres', 'safes')->name('safes')->middleware('throttle:20,1');
        Route::post('/{driver}', 'saveProvider')->where('driver', 'clicksign|d4sign')->name('providers.save');
        Route::post('/{driver}/testar', 'testProvider')->where('driver', 'clicksign|d4sign')->name('providers.test')->middleware('throttle:20,1');
        Route::delete('/{driver}', 'disconnect')->where('driver', 'clicksign|d4sign')->name('providers.destroy');
    });

    // Tribunais: publicações do DJEN (equipe), andamentos pelo Datajud e configuração (administradores).
    Route::controller(CourtController::class)->prefix('publicacoes')->name('publications.')->group(function () {
        Route::get('/', 'publications')->name('index');
        Route::get('/{publication}', 'show')->whereNumber('publication')->name('show');
        Route::get('/{publication}/vencimento', 'suggest')->whereNumber('publication')->name('suggest');
        Route::post('/{publication}/prazo', 'createDeadline')->whereNumber('publication')->name('deadline');
        Route::post('/{publication}/vincular', 'link')->whereNumber('publication')->name('link');
        Route::post('/{publication}/situacao', 'setStatus')->whereNumber('publication')->name('status');
    });
    Route::post('/processos/{legal_case}/tribunal/atualizar', [CourtController::class, 'refreshCase'])->name('cases.court.refresh')->middleware('throttle:20,1');
    Route::post('/processos/{legal_case}/tribunal/acompanhar', [CourtController::class, 'toggleCase'])->name('cases.court.toggle');
    Route::middleware('can:admin')->controller(CourtController::class)->prefix('integracoes/tribunais')->name('courts.')->group(function () {
        Route::get('/', 'settings')->name('settings');
        Route::post('/', 'save')->name('save');
        Route::post('/buscar', 'runNow')->name('run')->middleware('throttle:5,1');
        Route::post('/testar-datajud', 'testDatajud')->name('datajud.test')->middleware('throttle:20,1');
        Route::post('/oabs', 'storeLawyer')->name('lawyers.store');
        Route::post('/oabs/importar', 'importLawyers')->name('lawyers.import');
        Route::post('/oabs/{lawyer}/alternar', 'toggleLawyer')->name('lawyers.toggle');
        Route::delete('/oabs/{lawyer}', 'destroyLawyer')->name('lawyers.destroy');
    });

    Route::controller(WhatsappController::class)->prefix('whatsapp')->name('whatsapp.')->group(function () {
        Route::middleware('can:admin')->group(function () {
            Route::get('/canais', 'channels')->name('channels.index');
            Route::get('/canais/novo', 'createChannel')->name('channels.create');
            Route::post('/canais', 'storeChannel')->name('channels.store');
            Route::get('/canais/{channel}/editar', 'editChannel')->name('channels.edit');
            Route::put('/canais/{channel}', 'updateChannel')->name('channels.update');
            Route::delete('/canais/{channel}', 'destroyChannel')->name('channels.destroy');
            Route::post('/canais/{channel}/testar', 'testChannel')->name('channels.test')->middleware('throttle:20,1');
        });
        Route::get('/', 'index')->name('index');
        Route::get('/arquivos/{message}', 'media')->name('media');
        Route::get('/{conversation}', 'show')->whereNumber('conversation')->name('show');
        Route::get('/{conversation}/novas', 'poll')->whereNumber('conversation')->name('poll');
        Route::post('/{conversation}/mensagens', 'send')->whereNumber('conversation')->name('send')->middleware('throttle:60,1');
        Route::patch('/{conversation}/modo', 'mode')->whereNumber('conversation')->name('mode');
        Route::patch('/{conversation}/contato', 'linkContact')->whereNumber('conversation')->name('contact');
    });

    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->except('show')->names('users')
        ->middleware('can:admin');
});
