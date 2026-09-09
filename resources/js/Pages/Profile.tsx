import { FormEvent, useEffect, useState } from 'react';
import { useForm, Link, router } from '@inertiajs/react';
import { ArrowLeft, User, Lock, LogOut, CheckCircle, Monitor, ShieldAlert, ShieldCheck, KeyRound, Languages } from 'lucide-react';
import type { BrandingSettings, User as UserType } from '../types';
import { usePortalBranding } from '../branding';

interface Props {
    user: UserType;
    branding?: BrandingSettings;
    flash?: { success?: string; warning?: string };
}

interface BrowserSession {
    id: string;
    current: boolean;
    ip_address?: string | null;
    last_activity?: string | null;
    user_agent?: string | null;
}

interface TwoFactorSetup {
    secret: string;
    otpauth_uri: string;
}

export default function Profile({ user, flash }: Props) {
    const { branding } = usePortalBranding();
    const [sessions, setSessions] = useState<BrowserSession[]>([]);
    const [sessionMsg, setSessionMsg] = useState('');
    const [twoFactorEnabled, setTwoFactorEnabled] = useState(Boolean(user.two_factor_confirmed_at));
    const [twoFactorSetup, setTwoFactorSetup] = useState<TwoFactorSetup | null>(null);
    const [twoFactorCode, setTwoFactorCode] = useState('');
    const [twoFactorPassword, setTwoFactorPassword] = useState('');
    const [twoFactorBusy, setTwoFactorBusy] = useState(false);
    const [twoFactorMessage, setTwoFactorMessage] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const jsonError = async (response: Response, fallback: string) => {
        let data: any = {};
        try { data = await response.json(); } catch { return fallback; }
        if (response.status === 423 && data.redirect) {
            router.visit(data.redirect);
            return data.message || fallback;
        }
        return data.message || Object.values(data.errors || {}).flat().join('; ') || fallback;
    };

    const loadSessions = async () => {
        try {
            const response = await fetch('/sessions', { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (response.ok) setSessions(data.sessions || []);
        } catch {
            setSessionMsg('Could not load active sessions.');
        }
    };
    useEffect(() => { void loadSessions(); }, []);

    const logoutSession = async (id: string) => {
        const response = await fetch(`/sessions/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } });
        const data = await response.json();
        setSessionMsg(data.message || 'Session updated.');
        void loadSessions();
    };
    const logoutOthers = async () => {
        const response = await fetch('/sessions/others', { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } });
        const data = await response.json();
        setSessionMsg(data.message || 'Other sessions logged out.');
        void loadSessions();
    };

    const infoForm = useForm({
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
        preferred_language: user.preferred_language ?? branding.default_language ?? 'en',
    });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submitInfo = (e: FormEvent) => {
        e.preventDefault();
        infoForm.patch('/profile', { preserveScroll: true });
    };
    const submitPassword = (e: FormEvent) => {
        e.preventDefault();
        passwordForm.patch('/profile/password', { preserveScroll: true, onSuccess: () => passwordForm.reset() });
    };

    const startTwoFactor = async () => {
        setTwoFactorBusy(true); setTwoFactorMessage(''); setRecoveryCodes([]);
        try {
            const response = await fetch('/profile/two-factor/setup', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } });
            if (!response.ok) throw new Error(await jsonError(response, 'Could not start two-factor setup.'));
            setTwoFactorSetup(await response.json());
            setTwoFactorMessage('Add the account to your authenticator app, then enter the current 6-digit code.');
        } catch (error) {
            setTwoFactorMessage(error instanceof Error ? error.message : 'Could not start two-factor setup.');
        } finally { setTwoFactorBusy(false); }
    };

    const confirmTwoFactor = async () => {
        if (!twoFactorCode.trim()) return;
        setTwoFactorBusy(true); setTwoFactorMessage('');
        try {
            const response = await fetch('/profile/two-factor/confirm', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ code: twoFactorCode.trim() }),
            });
            if (!response.ok) throw new Error(await jsonError(response, 'Could not confirm two-factor authentication.'));
            const data = await response.json();
            setRecoveryCodes(data.recovery_codes || []);
            setTwoFactorEnabled(true);
            setTwoFactorSetup(null);
            setTwoFactorCode('');
            setTwoFactorMessage('Two-factor authentication is enabled. Save the recovery codes below now; they are shown only once.');
        } catch (error) {
            setTwoFactorMessage(error instanceof Error ? error.message : 'Could not confirm two-factor authentication.');
        } finally { setTwoFactorBusy(false); }
    };

    const disableTwoFactor = async () => {
        if (!twoFactorPassword) { setTwoFactorMessage('Enter your current password to disable two-factor authentication.'); return; }
        if (!confirm('Disable two-factor authentication for this account?')) return;
        setTwoFactorBusy(true); setTwoFactorMessage('');
        try {
            const response = await fetch('/profile/two-factor', {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ password: twoFactorPassword }),
            });
            if (!response.ok) throw new Error(await jsonError(response, 'Could not disable two-factor authentication.'));
            const data = await response.json();
            setTwoFactorEnabled(false); setTwoFactorPassword(''); setRecoveryCodes([]); setTwoFactorSetup(null);
            setTwoFactorMessage(data.message || 'Two-factor authentication disabled.');
        } catch (error) {
            setTwoFactorMessage(error instanceof Error ? error.message : 'Could not disable two-factor authentication.');
        } finally { setTwoFactorBusy(false); }
    };

    const initials = user.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();

    return (
        <div className="profile-page">
            <header className="profile-topbar">
                <Link href="/" className="profile-back-btn"><ArrowLeft size={16} /><span>Back to Dashboard</span></Link>
                <div className="profile-topbar-brand">
                    <img src={branding.logo_url} alt={branding.portal_name} onError={e => { if (!e.currentTarget.src.endsWith('/logo.svg')) e.currentTarget.src = '/logo.svg'; else e.currentTarget.style.display = 'none'; }} />
                    <span>{branding.portal_name}</span>
                </div>
                <button className="logout-btn" onClick={() => router.post('/logout')} title="Sign out"><LogOut size={16} /><span>Logout</span></button>
            </header>

            <div className="profile-body">
                <div className="profile-hero"><div className="profile-avatar-lg">{initials}</div><div><h1 className="profile-hero-name">{user.name}</h1><span className="profile-hero-role">{user.role.replace('-', ' ')}</span></div></div>

                {flash?.warning && <div className="profile-error-banner" style={{ marginBottom: 16 }}><ShieldAlert size={16} /><span>{flash.warning}</span></div>}
                {flash?.success && <div className="profile-flash"><CheckCircle size={16} /><span>{flash.success}</span></div>}

                <div className="profile-grid">
                    <div className="profile-card">
                        <h2 className="profile-card-title"><User size={18} />Personal Information</h2>
                        {infoForm.errors.email && <div className="profile-error-banner">{infoForm.errors.email}</div>}
                        <form onSubmit={submitInfo} className="profile-form">
                            <div className="profile-field"><label htmlFor="name">Full name</label><input id="name" type="text" value={infoForm.data.name} onChange={e => infoForm.setData('name', e.target.value)} />{infoForm.errors.name && <span className="profile-field-error">{infoForm.errors.name}</span>}</div>
                            <div className="profile-field"><label htmlFor="email">Email address</label><input id="email" type="email" value={infoForm.data.email} onChange={e => infoForm.setData('email', e.target.value)} />{infoForm.errors.email && <span className="profile-field-error">{infoForm.errors.email}</span>}</div>
                            <div className="profile-field"><label htmlFor="phone">Phone <span className="profile-optional">(optional)</span></label><input id="phone" type="tel" value={infoForm.data.phone} onChange={e => infoForm.setData('phone', e.target.value)} placeholder="e.g. +44 7700 900000" />{infoForm.errors.phone && <span className="profile-field-error">{infoForm.errors.phone}</span>}</div>
                            <div className="profile-field"><label htmlFor="preferred_language"><Languages size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />Interface language</label><select id="preferred_language" value={infoForm.data.preferred_language} onChange={e => infoForm.setData('preferred_language', e.target.value as 'en' | 'gu')}><option value="en">English</option><option value="gu">ગુજરાતી</option></select>{infoForm.errors.preferred_language && <span className="profile-field-error">{infoForm.errors.preferred_language}</span>}</div>
                            <div className="profile-field profile-field-static"><label>Role</label><span className="profile-role-badge">{user.role.replace('-', ' ')}</span></div>
                            <button type="submit" className="profile-save-btn" disabled={infoForm.processing}>{infoForm.processing ? 'Saving…' : 'Save changes'}</button>
                        </form>
                    </div>

                    <div className="profile-card">
                        <h2 className="profile-card-title"><Lock size={18} />Change Password</h2>
                        <form onSubmit={submitPassword} className="profile-form">
                            <div className="profile-field"><label htmlFor="current_password">Current password</label><input id="current_password" type="password" value={passwordForm.data.current_password} onChange={e => passwordForm.setData('current_password', e.target.value)} autoComplete="current-password" />{passwordForm.errors.current_password && <span className="profile-field-error">{passwordForm.errors.current_password}</span>}</div>
                            <div className="profile-field"><label htmlFor="new_password">New password</label><input id="new_password" type="password" value={passwordForm.data.password} onChange={e => passwordForm.setData('password', e.target.value)} placeholder="Use the configured password policy" autoComplete="new-password" />{passwordForm.errors.password && <span className="profile-field-error">{passwordForm.errors.password}</span>}</div>
                            <div className="profile-field"><label htmlFor="password_confirmation">Confirm new password</label><input id="password_confirmation" type="password" value={passwordForm.data.password_confirmation} onChange={e => passwordForm.setData('password_confirmation', e.target.value)} autoComplete="new-password" /></div>
                            <button type="submit" className="profile-save-btn" disabled={passwordForm.processing}>{passwordForm.processing ? 'Updating…' : 'Update password'}</button>
                        </form>
                    </div>

                    <div className="profile-card" style={{ gridColumn: '1 / -1' }}>
                        <h2 className="profile-card-title">{twoFactorEnabled ? <ShieldCheck size={18} /> : <KeyRound size={18} />}Two-Factor Authentication</h2>
                        <p style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 12 }}>Protect this account with a TOTP authenticator. Recovery codes can be used once if the authenticator is unavailable.</p>
                        {twoFactorMessage && <div className={twoFactorEnabled ? 'profile-flash' : 'profile-error-banner'} style={{ marginBottom: 12 }}>{twoFactorMessage}</div>}

                        {!twoFactorEnabled && !twoFactorSetup && <button className="btn btn-primary" disabled={twoFactorBusy} onClick={startTwoFactor}>{twoFactorBusy ? 'Preparing…' : 'Set up authenticator'}</button>}
                        {!twoFactorEnabled && twoFactorSetup && <div style={{ display: 'grid', gap: 12 }}>
                            <div className="profile-field"><label>Authenticator secret</label><input readOnly value={twoFactorSetup.secret} onFocus={e => e.currentTarget.select()} /></div>
                            <div className="profile-field"><label>Authenticator URI</label><input readOnly value={twoFactorSetup.otpauth_uri} onFocus={e => e.currentTarget.select()} /></div>
                            <div className="profile-field"><label htmlFor="two_factor_code">Current 6-digit code</label><input id="two_factor_code" inputMode="numeric" autoComplete="one-time-code" value={twoFactorCode} onChange={e => setTwoFactorCode(e.target.value)} /></div>
                            <div style={{ display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={twoFactorBusy || !twoFactorCode.trim()} onClick={confirmTwoFactor}>{twoFactorBusy ? 'Confirming…' : 'Confirm & enable'}</button><button className="btn btn-secondary" disabled={twoFactorBusy} onClick={() => { setTwoFactorSetup(null); setTwoFactorCode(''); setTwoFactorMessage(''); }}>Cancel</button></div>
                        </div>}
                        {twoFactorEnabled && <div style={{ display: 'grid', gap: 10, maxWidth: 480 }}><div className="profile-field"><label htmlFor="two_factor_password">Current password to disable</label><input id="two_factor_password" type="password" autoComplete="current-password" value={twoFactorPassword} onChange={e => setTwoFactorPassword(e.target.value)} /></div><button className="btn btn-secondary" disabled={twoFactorBusy} onClick={disableTwoFactor}>{twoFactorBusy ? 'Working…' : 'Disable two-factor authentication'}</button></div>}
                        {recoveryCodes.length > 0 && <div style={{ marginTop: 16, border: '1px solid var(--border-color)', borderRadius: 8, padding: 12 }}><strong>Recovery codes — store these securely now</strong><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 6, marginTop: 10, fontFamily: 'monospace' }}>{recoveryCodes.map(code => <code key={code}>{code}</code>)}</div></div>}
                    </div>

                    <div className="profile-card" style={{ gridColumn: '1 / -1' }}>
                        <h2 className="profile-card-title"><Monitor size={18} />Active Sessions</h2>
                        <p style={{ fontSize: 12, color: 'var(--text-secondary)' }}>Review browser/device sessions and revoke any session you no longer recognize.</p>
                        {sessionMsg && <div className="profile-flash" style={{ marginTop: 10 }}>{sessionMsg}</div>}
                        <div style={{ display: 'grid', gap: 8, marginTop: 12 }}>{sessions.map(session => <div key={session.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', border: '1px solid var(--border-color)', borderRadius: 8, padding: 10 }}><div><b>{session.current ? 'Current session' : 'Other session'}</b><div style={{ fontSize: 11, color: 'var(--text-secondary)' }}>{session.ip_address || 'Unknown IP'} · {session.last_activity ? new Date(session.last_activity).toLocaleString() : '-'}</div><div style={{ fontSize: 10, color: 'var(--text-muted)', maxWidth: 700, overflow: 'hidden', textOverflow: 'ellipsis' }}>{session.user_agent || 'Unknown browser'}</div></div>{!session.current && <button className="btn btn-secondary" onClick={() => logoutSession(session.id)}>Logout</button>}</div>)}</div>
                        <button className="btn btn-secondary" style={{ marginTop: 10 }} onClick={logoutOthers}>Logout All Other Sessions</button>
                    </div>
                </div>
            </div>
        </div>
    );
}
