<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class PasswordResetController extends Controller
{
    public function requestForm()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function send(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));
        $user = User::whereRaw('LOWER(email)=?', [$email])->first();

        if ($user && $user->isActive()) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );
            $url = url('/reset-password/'.$token).'?email='.urlencode($user->email);
            $branding = SystemSetting::valueFor('branding.settings', ['portal_name' => 'Karyalay Portal']);
            $portalName = trim((string) ($branding['portal_name'] ?? '')) ?: 'Karyalay Portal';
            try {
                Mail::raw(
                    "Reset your {$portalName} password: {$url}\nThis link is single-use and expires automatically.",
                    fn ($message) => $message->to($user->email)->subject($portalName.' password reset'),
                );
            } catch (\Throwable) {
                // Keep reset requests non-enumerating even when the mail provider is unavailable.
            }
        }

        return back()->with('success', 'If an active account exists for that email, a password-reset link has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request, string $token, AuditService $audit)
    {
        $min = max(8, (int) (SystemSetting::valueFor('security.auth', [])['password_min_length'] ?? 12));
        $data = $request->validate(['email' => 'required|email', 'password' => ['required', 'confirmed', Password::min($min)]]);
        $user = User::whereRaw('LOWER(email)=?', [strtolower(trim($data['email']))])->firstOrFail();
        abort_unless($user->isActive(), 422, 'Account is not active.');
        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $expiry = (int) (SystemSetting::valueFor('security.auth', [])['password_reset_expiry_minutes'] ?? 60);
        abort_unless($row && $row->created_at && now()->diffInMinutes($row->created_at) <= max(5, $expiry) && Hash::check($token, $row->token), 422, 'Reset link is invalid or expired.');
        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $audit->log($request, 'auth.password-reset', $user, 'Password reset completed.');
        return redirect()->route('login')->with('success', 'Password changed. Sign in with your new password.');
    }
}
