import { FormEvent } from 'react';
import { useForm, Link } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import PasswordInput from '../../Components/ui/PasswordInput';
import { useT } from '../../i18n';
import { usePortalBranding } from '../../branding';

export default function Register() {
    const t = useT();
    usePortalBranding();
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/register');
    };

    return (
        <div className="auth-container">
            <div className="auth-card">
                <AuthBrand />

                <h1 className="auth-title">{t('auth.create_account')}</h1>
                <p className="auth-subtitle">{t('auth.register_role_note', { role: t('roles.viewer') })}</p>

                <form onSubmit={submit} className="auth-form">
                    <div className="auth-field">
                        <label htmlFor="name">{t('auth.full_name')}</label>
                        <input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={e => setData('name', e.target.value)}
                            placeholder={t('auth.name_placeholder')}
                            autoFocus
                            autoComplete="name"
                        />
                        {errors.name && <span className="auth-error">{errors.name}</span>}
                    </div>

                    <div className="auth-field">
                        <label htmlFor="email">{t('auth.email_address')}</label>
                        <input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={e => setData('email', e.target.value)}
                            placeholder="you@example.com"
                            autoComplete="email"
                        />
                        {errors.email && <span className="auth-error">{errors.email}</span>}
                    </div>

                    <div className="auth-field">
                        <label htmlFor="phone">
                            {t('auth.phone')} <span className="auth-optional">({t('common.optional')})</span>
                        </label>
                        <input
                            id="phone"
                            type="tel"
                            value={data.phone}
                            onChange={e => setData('phone', e.target.value)}
                            placeholder={t('auth.phone_placeholder')}
                            autoComplete="tel"
                        />
                        {errors.phone && <span className="auth-error">{errors.phone}</span>}
                    </div>

                    <div className="auth-row">
                        <div className="auth-field">
                            <label htmlFor="password">{t('auth.password')}</label>
                            <PasswordInput
                                id="password"
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                placeholder={t('auth.password_min')}
                                autoComplete="new-password"
                            />
                            {errors.password && <span className="auth-error">{errors.password}</span>}
                        </div>

                        <div className="auth-field">
                            <label htmlFor="password_confirmation">{t('auth.confirm_password')}</label>
                            <PasswordInput
                                id="password_confirmation"
                                value={data.password_confirmation}
                                onChange={e => setData('password_confirmation', e.target.value)}
                                placeholder={t('auth.repeat_password')}
                                autoComplete="new-password"
                            />
                        </div>
                    </div>

                    <button type="submit" className="auth-submit" disabled={processing}>
                        {processing ? t('auth.creating_account') : t('auth.create_account')}
                    </button>
                </form>

                <p className="auth-footer-text">
                    {t('auth.have_account')}{' '}
                    <Link href="/login" className="auth-link">{t('auth.sign_in')}</Link>
                </p>
            </div>
        </div>
    );
}
