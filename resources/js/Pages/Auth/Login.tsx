import { FormEvent } from 'react';
import { useForm, Link } from '@inertiajs/react';

export default function Login() {
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
                <div className="auth-brand">
                    <img src="/logo.svg" alt="SMVS" onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }} />
                    <span>SMVS Storage</span>
                </div>

                <h1 className="auth-title">Welcome back</h1>
                <p className="auth-subtitle">Sign in to your account to continue</p>

                <form onSubmit={submit} className="auth-form">
                    <div className="auth-field">
                        <label htmlFor="email">Email address</label>
                        <input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={e => setData('email', e.target.value)}
                            placeholder="you@example.com"
                            autoFocus
                            autoComplete="email"
                        />
                        {errors.email && <span className="auth-error">{errors.email}</span>}
                    </div>

                    <div className="auth-field">
                        <label htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={e => setData('password', e.target.value)}
                            placeholder="••••••••"
                            autoComplete="current-password"
                        />
                        {errors.password && <span className="auth-error">{errors.password}</span>}
                    </div>

                    <label className="auth-checkbox">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={e => setData('remember', e.target.checked)}
                        />
                        <span>Remember me</span>
                    </label>
                    <div style={{textAlign:'right'}}><Link href="/forgot-password" className="auth-link">Forgot password?</Link></div>

                    <button type="submit" className="auth-submit" disabled={processing}>
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>

                <p className="auth-footer-text">
                    Don't have an account?{' '}
                    <Link href="/register" className="auth-link">Create one</Link>
                </p>
            </div>
        </div>
    );
}
