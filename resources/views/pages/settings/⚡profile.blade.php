<?php

use App\Concerns\ProfileValidationRules;
/* @chisel-email-verification */
use Illuminate\Contracts\Auth\MustVerifyEmail;
/* @end-chisel-email-verification */
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Perfil')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /* @chisel-email-verification */
    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
    /* @end-chisel-email-verification */
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                {{-- @chisel-email-verification --}}
                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
                {{-- @end-chisel-email-verification --}}
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if (auth()->user()?->canDo('users.view'))
            @php
                $cajaEnvironments = app(\App\Support\Caja\CajaDatabaseEnvironment::class);
                $cajaEnvironment = $cajaEnvironments->selected(request());
            @endphp

            @if ($cajaEnvironments->enabled())
                <flux:separator />

                <section class="my-6" data-test="profile-caja-environment-switcher">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading>Base de datos de Caja</flux:heading>
                            <flux:subheading>Elige la base que utilizará tu sesión actual.</flux:subheading>
                        </div>

                        <flux:badge :color="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL ? 'red' : 'amber'">
                            Activa: {{ $cajaEnvironments->label($cajaEnvironment) }}
                        </flux:badge>
                    </div>

                    @if (session('caja_environment_status'))
                        <div class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                            {{ session('caja_environment_status') }}
                        </div>
                    @endif

                    @error('caja_environment')
                        <div class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-400/10 dark:text-red-300">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <form method="POST" action="{{ route('caja-environment.update') }}">
                            @csrf
                            <input type="hidden" name="environment" value="development">
                            <flux:button
                                type="submit"
                                variant="{{ $cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::DEVELOPMENT ? 'primary' : 'filled' }}"
                                icon="beaker"
                                class="w-full"
                                :disabled="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::DEVELOPMENT"
                            >
                                Usar desarrollo
                            </flux:button>
                        </form>

                        <form
                            method="POST"
                            action="{{ route('caja-environment.update') }}"
                            onsubmit="return confirm('Cambiarás a la base institucional real. Los cobros y anulaciones afectarán datos operativos. ¿Deseas continuar?')"
                        >
                            @csrf
                            <input type="hidden" name="environment" value="institutional">
                            <flux:button
                                type="submit"
                                variant="{{ $cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL ? 'danger' : 'filled' }}"
                                icon="building-office"
                                class="w-full"
                                :disabled="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL"
                            >
                                Usar institucional
                            </flux:button>
                        </form>
                    </div>

                    <flux:text class="mt-3 text-xs">
                        El cambio es por sesión, valida la conexión y queda registrado en auditoría.
                    </flux:text>
                </section>
            @endif
        @endif

        {{-- @chisel-email-verification --}}
        @if ($this->showDeleteUser)
        {{-- @end-chisel-email-verification --}}
            <livewire:pages::settings.delete-user-form />
        {{-- @chisel-email-verification --}}
        @endif
        {{-- @end-chisel-email-verification --}}
    </x-pages::settings.layout>
</section>
