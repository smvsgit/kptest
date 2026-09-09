import { FormEvent } from 'react';
import { useForm, Link } from '@inertiajs/react';
import AuthBrand from '../../Components/AuthBrand';
import { usePortalBranding } from '../../branding';

export default function Register() {
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

                <h1 className="auth-title">Create account</h1>
                <p className="auth-subtitle">New accounts are assigned the <strong>Viewer</strong> role</p>

                <form onSubmit={submit} className="auth-form">
                    <div className="auth-field">
                        <label htmlFor="name">Full name</label>
                        <input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={e => setData('name', e.target.value)}
                            placeholder="John Doe"
                            autoFocus
                            autoComplete="name"
                        />
                        {errors.name && <span className="auth-error">{errors.name}</span>}
                    </div>

                    <div className="auth-field">
                        <label htmlFor="email">Email address</label>
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
                            Phone <span className="auth-optional">(optional)</span>
                        </label>
                        <input
                            id="phone"
                            type="tel"
                            value={data.phone}
                            onChange={e => setData('phone', e.target.value)}
                            placeholder="+1 234 567 8900"
                            autoComplete="tel"
                        />
                        {errors.phone && <span className="auth-error">{errors.phone}</span>}
                    </div>

                    <div className="auth-row">
                        <div className="auth-field">
                            <label htmlFor="password">Password</label>
                            <input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                placeholder="Min. 8 characters"
                                autoComplete="new-password"
                            />
                            {errors.password && <span className="auth-error">{errors.password}</span>}
                        </div>

                        <div className="auth-field">
                            <label htmlFor="password_confirmation">Confirm password</label>
                            <input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={e => setData('password_confirmation', e.target.value)}
                                placeholder="Repeat password"
                                autoComplete="new-password"
                            />
                        </div>
                    </div>

                    <button type="submit" className="auth-submit" disabled={processing}>
                        {processing ? 'Creating account…' : 'Create account'}
                    </button>
                </form>

                <p className="auth-footer-text">
                    Already have an account?{' '}
                    <Link href="/login" className="auth-link">Sign in</Link>
                </p>
            </div>
        </div>
    );
}
