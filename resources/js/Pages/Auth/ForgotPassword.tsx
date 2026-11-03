import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';
import { useT } from '../../i18n';

export default function ForgotPassword() {
    const { branding } = usePortalBranding();
    const t = useT();
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/forgot-password'); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">{t('auth.reset_title')}</h1>
            <p className="auth-subtitle">{t('auth.reset_intro', { portal: branding.portal_name })}</p>
            <form onSubmit={submit} className="auth-form">
                <div className="auth-field"><label htmlFor="email">{t('auth.email_address')}</label><input id="email" type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} autoFocus autoComplete="email" />{form.errors.email && <span className="auth-error">{form.errors.email}</span>}</div>
                <button className="auth-submit" disabled={form.processing}>{form.processing ? t('auth.sending') : t('auth.send_reset_link')}</button>
            </form>
            <p className="auth-footer-text"><Link className="auth-link" href="/login">{t('auth.back_to_sign_in')}</Link></p>
        </div></div>
    );
}
