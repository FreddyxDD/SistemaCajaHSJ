<?php

use App\Models\Caja\ChargeDocument;
use App\Models\VoidRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Solicitudes de anulación')] class extends Component {
    use WithPagination;

    public string $statusFilter = 'pending';

    public ?int $reviewingId = null;

    public string $reviewNotes = '';

    #[Computed]
    public function canApprove(): bool
    {
        $user = Auth::user();

        return $user->hasPermission('caja.void.approve') || $user->hasRole('administrador');
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'pending' => VoidRequest::query()->where('status', VoidRequest::STATUS_PENDING)->count(),
            'approved' => VoidRequest::query()->where('status', VoidRequest::STATUS_APPROVED)->count(),
            'rejected' => VoidRequest::query()->where('status', VoidRequest::STATUS_REJECTED)->count(),
        ];
    }

    #[Computed]
    public function requests()
    {
        return VoidRequest::query()
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            // Un cajero sin permiso de aprobacion solo ve sus propias solicitudes.
            ->when(! $this->canApprove, fn ($q) => $q->where('requested_by_user_id', Auth::id()))
            ->orderByDesc('requested_at')
            ->paginate(15);
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->reviewingId = null;
        $this->resetPage();
    }

    public function startReview(int $id): void
    {
        $this->reviewingId = $this->reviewingId === $id ? null : $id;
        $this->reviewNotes = '';
        $this->resetErrorBag();
    }

    public function approve(int $id): void
    {
        $request = $this->authorizeReview($id);

        // La anulacion real en el esquema legado ocurre SOLO aqui, al aprobarse:
        // hasta este momento el comprobante sigue vigente en Cabecera_documento_MH.
        DB::connection('caja')->transaction(function () use ($request) {
            ChargeDocument::query()
                ->where('id_documento', $request->document_id)
                ->update([
                    'estado_doc' => ChargeDocument::ESTADO_ANULADO,
                    'cod_motiv_anu' => $request->void_reason_code,
                ]);
        });

        $request->update([
            'status' => VoidRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_by_name' => Auth::user()->name,
            'reviewed_by_role' => $this->reviewerRole(),
            'review_notes' => $this->reviewNotes ?: null,
            'reviewed_at' => now(),
        ]);

        $this->reviewingId = null;
        $this->reviewNotes = '';
        session()->flash('ok', "Anulación aprobada. El comprobante {$request->document_number} quedó anulado.");
    }

    public function reject(int $id): void
    {
        $request = $this->authorizeReview($id);

        $this->validate(
            ['reviewNotes' => ['required', 'string', 'min:5']],
            ['reviewNotes.required' => 'Indica el motivo del rechazo.', 'reviewNotes.min' => 'Explica brevemente el motivo del rechazo.']
        );

        $request->update([
            'status' => VoidRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_by_name' => Auth::user()->name,
            'reviewed_by_role' => $this->reviewerRole(),
            'review_notes' => $this->reviewNotes,
            'reviewed_at' => now(),
        ]);

        $this->reviewingId = null;
        $this->reviewNotes = '';
        session()->flash('ok', "Solicitud rechazada. El comprobante {$request->document_number} sigue vigente.");
    }

    private function authorizeReview(int $id): VoidRequest
    {
        abort_unless($this->canApprove, 403, 'Solo el Jefe de Economía o el Cajero central pueden aprobar anulaciones.');

        $request = VoidRequest::query()->findOrFail($id);

        abort_unless($request->isPending(), 409, 'Esta solicitud ya fue revisada.');

        return $request;
    }

    private function reviewerRole(): string
    {
        $user = Auth::user();

        return match (true) {
            $user->hasRole('jefe_economia') => 'jefe_economia',
            $user->hasRole('cajero_central') => 'cajero_central',
            default => 'administrador',
        };
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">Solicitudes de anulación</flux:heading>
        <flux:text class="text-zinc-500">
            @if ($this->canApprove)
                Como aprobador, puedes autorizar o rechazar las anulaciones solicitadas por los cajeros.
            @else
                Aquí ves el estado de las anulaciones que has solicitado. Solo el Jefe de Economía o el Cajero central pueden aprobarlas.
            @endif
        </flux:text>
    </div>

    @if (session('ok'))
        <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-500/30 dark:bg-indigo-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
            <flux:text class="text-indigo-800 dark:text-indigo-300">{{ session('ok') }}</flux:text>
        </div>
    @endif

    {{-- Filtros por estado --}}
    <div class="flex flex-wrap gap-2">
        @php
            $filters = [
                'pending' => ['Pendientes', $this->counts['pending'], 'amber'],
                'approved' => ['Aprobadas', $this->counts['approved'], 'green'],
                'rejected' => ['Rechazadas', $this->counts['rejected'], 'red'],
                'all' => ['Todas', array_sum($this->counts), 'zinc'],
            ];
        @endphp
        @foreach ($filters as $key => [$label, $count, $color])
            <button
                type="button"
                wire:click="setStatusFilter('{{ $key }}')"
                class="flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium {{ $statusFilter === $key ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}"
            >
                {{ $label }}
                <span class="rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-white/10">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($this->requests as $req)
            <div class="rounded-xl border {{ $req->isPending() ? 'border-amber-200 dark:border-amber-500/30' : 'border-zinc-200 dark:border-white/10' }} bg-white p-4 dark:bg-white/5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('caja.charges.show', $req->document_id) }}" wire:navigate class="font-semibold hover:underline">
                                {{ $req->document_number }}
                            </a>
                            <flux:badge :color="$req->status_color" size="sm">{{ $req->status_label }}</flux:badge>
                            <flux:text class="font-semibold">S/ {{ number_format($req->document_total, 2) }}</flux:text>
                        </div>

                        <div class="mt-1 space-y-0.5 text-sm text-zinc-500">
                            <div><span class="font-medium text-zinc-700 dark:text-zinc-300">Motivo:</span> {{ $req->void_reason_label }}</div>
                            @if ($req->request_notes)
                                <div class="italic">"{{ $req->request_notes }}"</div>
                            @endif
                            <div class="flex flex-wrap items-center gap-x-2 text-xs">
                                <flux:icon.user class="size-3.5" />
                                <span>Solicitó {{ $req->requested_by_name }}</span>
                                <span>·</span>
                                <span>{{ $req->requested_at?->format('d/m/Y H:i') }}</span>
                                @if ($req->cash_session_code)
                                    <span>·</span>
                                    <span>Turno {{ $req->cash_session_code }}</span>
                                @endif
                            </div>
                            @if (! $req->isPending())
                                <div class="flex flex-wrap items-center gap-x-2 text-xs">
                                    <flux:icon.shield-check class="size-3.5" />
                                    <span>{{ $req->status === 'approved' ? 'Aprobó' : 'Rechazó' }} {{ $req->reviewed_by_name }}</span>
                                    <span>·</span>
                                    <span>{{ $req->reviewed_at?->format('d/m/Y H:i') }}</span>
                                </div>
                                @if ($req->review_notes)
                                    <div class="text-xs italic">Revisión: "{{ $req->review_notes }}"</div>
                                @endif
                            @endif
                        </div>
                    </div>

                    @if ($req->isPending() && $this->canApprove)
                        <flux:button size="sm" variant="primary" wire:click="startReview({{ $req->id }})">
                            {{ $reviewingId === $req->id ? 'Cerrar' : 'Revisar' }}
                        </flux:button>
                    @endif
                </div>

                @if ($reviewingId === $req->id && $req->isPending() && $this->canApprove)
                    <div class="mt-4 space-y-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-start gap-2">
                            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                            <flux:text class="text-sm">
                                Aprobar anulará el comprobante <strong>{{ $req->document_number }}</strong> por S/ {{ number_format($req->document_total, 2) }}.
                                Esta acción se registra a tu nombre y no se puede revertir.
                            </flux:text>
                        </div>

                        <flux:textarea wire:model="reviewNotes" rows="2" placeholder="Observación (obligatoria si rechazas)" />
                        @error('reviewNotes') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                        <div class="flex flex-wrap gap-2">
                            <flux:button variant="primary" wire:click="approve({{ $req->id }})" icon="check">Aprobar anulación</flux:button>
                            <flux:button variant="danger" wire:click="reject({{ $req->id }})" icon="x-mark">Rechazar</flux:button>
                            <flux:button variant="ghost" wire:click="startReview({{ $req->id }})">Cancelar</flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 px-4 py-16 text-center dark:border-zinc-700">
                <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-3 text-sm text-zinc-500">
                    No hay solicitudes {{ $statusFilter === 'all' ? '' : match($statusFilter) { 'pending' => 'pendientes', 'approved' => 'aprobadas', default => 'rechazadas' } }}.
                </flux:text>
            </div>
        @endforelse
    </div>

    {{ $this->requests->links() }}
</section>
