<?php

use App\Models\Caja\CashSession;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Turno de caja')] class extends Component {
    #[Computed]
    public function openSession(): ?CashSession
    {
        return CashSession::query()
            ->open()
            ->where('cod_usu', LegacyIdGenerator::legacyUserCode(Auth::user()))
            ->first();
    }

    #[Computed]
    public function recentSessions()
    {
        return CashSession::query()
            ->where('cod_usu', LegacyIdGenerator::legacyUserCode(Auth::user()))
            // fecha_apertura/hora_apertura son texto (DD/MM/YYYY), no ordenan
            // cronologicamente; cod_aper_cierre_caja es un correlativo monotonico.
            ->orderByDesc('cod_aper_cierre_caja')
            ->limit(10)
            ->get();
    }

    public function open(): void
    {
        abort_unless(Auth::user()->hasPermission('caja.session.open') || Auth::user()->hasRole('administrador'), 403);

        if ($this->openSession) {
            $this->addError('session', 'Ya tienes un turno de caja abierto.');

            return;
        }

        CashSession::query()->create([
            'cod_aper_cierre_caja' => LegacyIdGenerator::nextCashSessionCode(),
            'cod_usu' => LegacyIdGenerator::legacyUserCode(Auth::user()),
            'fecha_apertura' => now()->format('d/m/Y'),
            'hora_apertura' => now()->format('H:i:s'),
            'fecha_cierre' => '00/00/0000',
            'hora_cierre' => '00:00:00',
            'estado_aper_cierre_caja' => CashSession::ESTADO_ABIERTO,
        ]);

        unset($this->openSession, $this->recentSessions);
    }

    public function close(): void
    {
        abort_unless(Auth::user()->hasPermission('caja.session.close') || Auth::user()->hasRole('administrador'), 403);

        $session = $this->openSession;

        if (! $session) {
            return;
        }

        $session->update([
            'fecha_cierre' => now()->format('d/m/Y'),
            'hora_cierre' => now()->format('H:i:s'),
            'estado_aper_cierre_caja' => CashSession::ESTADO_CERRADO,
        ]);

        unset($this->openSession, $this->recentSessions);
    }
}; ?>

<section class="w-full max-w-3xl mx-auto space-y-6">
    <flux:heading size="xl">Turno de caja</flux:heading>

    @error('session')
        <flux:callout variant="danger" heading="{{ $message }}" />
    @enderror

    @if ($this->openSession)
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:subheading>Turno abierto</flux:subheading>
                    <flux:heading size="lg">{{ $this->openSession->cod_aper_cierre_caja }}</flux:heading>
                    <flux:text class="mt-1">Abierto el {{ $this->openSession->fecha_apertura }} a las {{ $this->openSession->hora_apertura }}</flux:text>
                </div>
                <flux:badge color="green">Abierto</flux:badge>
            </div>

            <div class="flex gap-2">
                <flux:button href="{{ route('caja.charges.create') }}" variant="primary">Registrar cobro</flux:button>
                <flux:button wire:click="close" wire:confirm="¿Cerrar el turno de caja actual?" variant="danger">Cerrar turno</flux:button>
            </div>
        </flux:card>
    @else
        <flux:card class="space-y-4">
            <flux:text>No tienes un turno de caja abierto. Debes abrir uno antes de registrar cobros.</flux:text>
            <flux:button wire:click="open" variant="primary">Abrir turno de caja</flux:button>
        </flux:card>
    @endif

    <div>
        <flux:subheading class="mb-2">Turnos recientes</flux:subheading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Codigo</flux:table.column>
                <flux:table.column>Apertura</flux:table.column>
                <flux:table.column>Cierre</flux:table.column>
                <flux:table.column>Estado</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->recentSessions as $session)
                    <flux:table.row>
                        <flux:table.cell>{{ $session->cod_aper_cierre_caja }}</flux:table.cell>
                        <flux:table.cell>{{ $session->fecha_apertura }} {{ $session->hora_apertura }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($session->isOpen())
                                &mdash;
                            @else
                                {{ $session->fecha_cierre }} {{ $session->hora_cierre }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$session->isOpen() ? 'green' : 'zinc'">
                                {{ $session->isOpen() ? 'Abierto' : 'Cerrado' }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
