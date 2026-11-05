import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import PasswordInput from '../../Components/ui/PasswordInput';
import { usePortalBranding } from '../../branding';
import { useT } from '../../i18n';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    usePortalBranding();
    const t = useT();
    const form = useForm({ email: email || '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post(`/reset-password/${encodeURIComponent(token)}`); };
    return (
        <div className="auth-container"><div className="auth-card">
            <AuthBrand />
            <h1 className="auth-title">{t('auth.choose_new_password')}</h1>
            <form onSubmit={submit} className="auth-form">
                <div className="auth-field"><label htmlFor="email">{t('auth.email')}</label><input id="email" type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} autoComplete="email" /></div>
                <div className="auth-field"><label htmlFor="password">{t('auth.new_password')}</label><PasswordInput id="password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} autoComplete="new-password" /></div>
                <div className="auth-field"><label htmlFor="password_confirmation">{t('auth.confirm_password')}</label><PasswordInput id="password_confirmation" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" /></div>
                {Object.values(form.errors).map((error, index) => <span className="auth-error" key={index}>{error}</span>)}
                <button className="auth-submit" disabled={form.processing}>{t('auth.reset_password')}</button>
            </form>
        </div></div>
    );
}
