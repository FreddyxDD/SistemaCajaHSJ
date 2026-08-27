<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\AccessAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TemporaryPasswordController extends Controller
{
    use PasswordValidationRules;

    public function edit(): View
    {
        return view('auth.temporary-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = $request->user();
        $account = $user->accessAccount;

        abort_unless($account instanceof AccessAccount, 422, 'La cuenta de acceso no esta configurada.');

        DB::connection('identity')->transaction(function () use ($user, $account, $validated): void {
            $password = Hash::make($validated['password']);

            $user->forceFill(['password' => $password])->save();
            $account->forceFill([
                'password' => $password,
                'must_change_password' => false,
            ])->save();
        });

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Contrasena actualizada correctamente.');
    }
}
