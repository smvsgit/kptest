import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';

export default function TwoFactorChallenge() {
    usePortalBranding();
    const form = useForm({ code: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/two-factor-challenge'); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">Two-factor verification</h1>
            <p className="auth-subtitle">Enter the 6-digit authenticator code or one unused recovery code.</p>
            <form className="auth-form" onSubmit={submit}>
                <div className="auth-field"><label htmlFor="code">Verification code</label><input id="code" autoFocus autoComplete="one-time-code" value={form.data.code} onChange={e => form.setData('code', e.target.value)} />{form.errors.code && <span className="auth-error">{form.errors.code}</span>}</div>
                <button className="auth-submit" disabled={form.processing}>Verify</button>
            </form>
            <button type="button" className="btn btn-secondary" style={{ width: '100%', marginTop: 12 }} onClick={() => router.post('/logout')}>Sign out</button>
        </div></div>
    );
}
