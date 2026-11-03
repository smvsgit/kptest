import { FormEvent } from 'react';
import { useForm, Link } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';
import { useT } from '../../i18n';

export default function Login() {
    const { branding } = usePortalBranding();
    const t = useT();
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <div className="auth-container">
            <div className="auth-card">
                <AuthBrand />

                <h1 className="auth-title">{t('auth.welcome_back')}</h1>
                <p className="auth-subtitle">{branding.login_text}</p>

                <form onSubmit={submit} className="auth-form">
                    <div className="auth-field">
                        <label htmlFor="email">{t('auth.email_address')}</label>
                        <input id="email" type="email" value={data.email} onChange={e => setData('email', e.target.value)} placeholder="you@example.com" autoFocus autoComplete="email" />
                        {errors.email && <span className="auth-error">{errors.email}</span>}
                    </div>

                    <div className="auth-field">
                        <label htmlFor="password">{t('auth.password')}</label>
                        <input id="password" type="password" value={data.password} onChange={e => setData('password', e.target.value)} placeholder="••••••••" autoComplete="current-password" />
                        {errors.password && <span className="auth-error">{errors.password}</span>}
                    </div>

                    <label className="auth-checkbox">
                        <input type="checkbox" checked={data.remember} onChange={e => setData('remember', e.target.checked)} />
                        <span>{t('auth.remember_me')}</span>
                    </label>
                    <div style={{ textAlign: 'right' }}><Link href="/forgot-password" className="auth-link">{t('auth.forgot_password')}</Link></div>

                    <button type="submit" className="auth-submit" disabled={processing}>
                        {processing ? t('auth.signing_in') : t('auth.sign_in')}
                    </button>
                </form>

                <p className="auth-footer-text">
                    {t('auth.no_account')} <Link href="/register" className="auth-link">{t('auth.create_one')}</Link>
                </p>
            </div>
        </div>
    );
}
