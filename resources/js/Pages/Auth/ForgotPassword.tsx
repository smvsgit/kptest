import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';

export default function ForgotPassword() {
    const { branding } = usePortalBranding();
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/forgot-password'); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">Reset password</h1>
            <p className="auth-subtitle">Enter your {branding.portal_name} account email. Active accounts receive a single-use reset link.</p>
            <form onSubmit={submit} className="auth-form">
                <div className="auth-field"><label htmlFor="email">Email address</label><input id="email" type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} autoFocus autoComplete="email" />{form.errors.email && <span className="auth-error">{form.errors.email}</span>}</div>
                <button className="auth-submit" disabled={form.processing}>{form.processing ? 'Sending…' : 'Send reset link'}</button>
            </form>
            <p className="auth-footer-text"><Link className="auth-link" href="/login">Back to sign in</Link></p>
        </div></div>
    );
}
