<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactorService $totp)
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => 'Two-factor authentication is already enabled.']);
        }

        $secret = $totp->generateSecret();
        $request->session()->put('2fa_setup_secret', $secret);
        $issuer = SystemSetting::valueFor('security.two_factor', ['issuer' => 'Karyalay Portal'])['issuer'] ?? 'Karyalay Portal';

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->uri($issuer, $user->email, $secret),
        ]);
    }

    public function confirm(Request $request, TwoFactorService $totp, AuditService $audit)
    {
        $data = $request->validate(['code' => 'required|string']);
        $secret = (string) $request->session()->get('2fa_setup_secret', '');

        if (! $secret || ! $totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid verification code.']);
        }

        $codes = $totp->recoveryCodes();
        $request->user()->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($code) => Hash::make($code), $codes))),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('2fa_setup_secret');
        $request->session()->put('2fa_passed_at', time());
        $audit->log($request, 'security.2fa.enabled', $request->user(), 'Two-factor authentication enabled.');

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request, AuditService $audit)
    {
        $request->validate(['password' => 'required|current_password']);
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $request->session()->forget(['2fa_setup_secret', '2fa_passed_at']);
        $audit->log($request, 'security.2fa.disabled', $request->user(), 'Two-factor authentication disabled.');

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Two-factor authentication disabled.']);
        }

        return back()->with('success', 'Two-factor authentication disabled.');
    }

    public function challenge(Request $request, TwoFactorService $totp, AuditService $audit)
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => 'Two-factor authentication is not enabled.']);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        if ($totp->verify($secret, $data['code'])) {
            $request->session()->put('2fa_passed_at', time());
            $audit->log($request, 'security.2fa.challenge-passed', $user, 'Two-factor authentication challenge passed.');
            return redirect()->intended(route('dashboard'));
        }

        $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
        foreach ($codes as $index => $hash) {
            if (! Hash::check(strtoupper(trim($data['code'])), $hash)) {
                continue;
            }

            unset($codes[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
            ])->save();
            $request->session()->put('2fa_passed_at', time());
            $audit->log($request, 'security.2fa.recovery-used', $user, 'Two-factor recovery code used.');
            return redirect()->intended(route('dashboard'));
        }

        throw ValidationException::withMessages(['code' => 'Invalid authenticator or recovery code.']);
    }
}
