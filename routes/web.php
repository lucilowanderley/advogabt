<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\LegalCaseController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->except('show')->names('users')
        ->middleware('can:admin');
});
