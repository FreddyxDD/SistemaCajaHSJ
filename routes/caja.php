<?php

use App\Http\Controllers\Caja\CashierDailyReportController;
use App\Http\Controllers\Caja\CashSessionReportController;
use App\Http\Controllers\Caja\ReceiptTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('caja')->name('caja.')->group(function () {
    Route::livewire('turno', 'pages::caja.sessions')
        ->middleware('permission:caja.view')
        ->name('sessions.index');

    // Consulta del tarifario para personal que no opera caja (asistentas sociales,
    // servicios). Solo lectura: no abre turno ni emite comprobantes.
    Route::livewire('consulta-precios', 'pages::caja.price-lookup')
        ->middleware('permission:caja.prices.view')
        ->name('prices.lookup');

    Route::livewire('cajeros', 'pages::caja.cashiers')
        ->middleware('permission:caja.cashiers.view')
        ->name('cashiers.index');

    Route::livewire('turnos/{sessionCode}', 'pages::caja.session-show')
        ->middleware('permission:caja.cashiers.view')
        ->name('sessions.show');

    // Reporte contable del turno en A4, para imprimir y archivar.
    Route::get('turnos/{sessionCode}/reporte-contable', CashSessionReportController::class)
        ->middleware('permission:caja.view')
        ->name('sessions.report');

    // Reporte diario del cajero: el que se entrega firmado al cajero central.
    // Mismo contenido en A4 y en ticketera segun ?formato=
    Route::get('reporte-diario', CashierDailyReportController::class)
        ->middleware('permission:caja.view')
        ->name('daily-report');

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

    // Mantenimiento del catalogo facturable y su precio en cada forma de pago.
    Route::livewire('catalogo', 'pages::admin.catalog')
        ->middleware('permission:caja.catalog.manage')
        ->name('catalog.index');

    // Auditoria de cambios del catalogo: solo lectura, para costos y auditoria interna.
    Route::livewire('catalogo/auditoria', 'pages::admin.catalog-audit')
        ->middleware('permission:caja.catalog.audit')
        ->name('catalog.audit');

    // Auditoria de accesos: quien entra, desde donde y que consulta.
    Route::livewire('accesos', 'pages::admin.access-audit')
        ->middleware('permission:audit.access.view')
        ->name('access-audit');
});
