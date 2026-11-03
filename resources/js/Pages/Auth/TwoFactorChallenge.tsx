import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';
import { useT } from '../../i18n';

export default function TwoFactorChallenge() {
    usePortalBranding();
    const t = useT();
    const form = useForm({ code: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/two-factor-challenge'); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">{t('auth.two_factor_title')}</h1>
            <p className="auth-subtitle">{t('auth.two_factor_intro')}</p>
            <form className="auth-form" onSubmit={submit}>
                <div className="auth-field"><label htmlFor="code">{t('auth.verification_code')}</label><input id="code" autoFocus autoComplete="one-time-code" value={form.data.code} onChange={e => form.setData('code', e.target.value)} />{form.errors.code && <span className="auth-error">{form.errors.code}</span>}</div>
                <button className="auth-submit" disabled={form.processing}>{t('auth.verify')}</button>
            </form>
            <button type="button" className="btn btn-secondary" style={{ width: '100%', marginTop: 12 }} onClick={() => router.post('/logout')}>{t('auth.sign_out')}</button>
        </div></div>
    );
}
