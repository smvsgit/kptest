import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    usePortalBranding();
    const form = useForm({ email: email || '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/reset-password/${encodeURIComponent(token)}`); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">Choose a new password</h1>
            <form onSubmit={submit} className="auth-form">
                <div className="auth-field"><label htmlFor="email">Email</label><input id="email" type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} autoComplete="email" /></div>
                <div className="auth-field"><label htmlFor="password">New password</label><input id="password" type="password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} autoComplete="new-password" /></div>
                <div className="auth-field"><label htmlFor="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" /></div>
                {Object.values(form.errors).map((error, index) => <span className="auth-error" key={index}>{error}</span>)}
                <button className="auth-submit" disabled={form.processing}>Reset password</button>
            </form>
        </div></div>
    );
}
