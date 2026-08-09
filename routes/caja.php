<?php

use App\Http\Controllers\Caja\ReceiptTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('caja')->name('caja.')->group(function () {
    Route::livewire('turno', 'pages::caja.sessions')
        ->middleware('permission:caja.view')
        ->name('sessions.index');

    Route::livewire('cajeros', 'pages::caja.cashiers')
        ->middleware('permission:caja.cashiers.view')
        ->name('cashiers.index');

    Route::livewire('turnos/{sessionCode}', 'pages::caja.session-show')
        ->middleware('permission:caja.cashiers.view')
        ->name('sessions.show');

    Route::livewire('cobros', 'pages::caja.charges')
        ->middleware('permission:caja.view')
        ->name('charges.index');

    Route::livewire('cobros/nuevo', 'pages::caja.new-charge')
        ->middleware('permission:caja.charge.create')
        ->name('charges.create');

    // Ticket para impresora termica; se carga en un iframe oculto y se imprime solo.
    Route::get('cobros/{documentId}/ticket', ReceiptTicketController::class)
        ->middleware('permission:caja.view')
        ->name('charges.ticket');

    Route::livewire('cobros/{documentId}', 'pages::caja.charge-show')
        ->middleware('permission:caja.view')
        ->name('charges.show');

    Route::livewire('anulaciones', 'pages::caja.void-requests')
        ->middleware('permission:caja.void.request')
        ->name('void-requests.index');

    Route::livewire('reportes', 'pages::caja.reports')
        ->middleware('permission:reports.view')
        ->name('reports.index');
});

Route::middleware(['auth', 'verified'])->prefix('administracion')->name('admin.')->group(function () {
    Route::livewire('usuarios', 'pages::admin.users')
        ->middleware('permission:users.view')
        ->name('users.index');

    Route::livewire('cajeros-sistema', 'pages::admin.legacy-cashiers')
        ->middleware('permission:users.view')
        ->name('legacy-cashiers.index');
});
