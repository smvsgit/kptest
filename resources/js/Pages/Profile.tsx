import { FormEvent, useEffect, useState } from 'react';
import { useForm, Link, router } from '@inertiajs/react';
import { ArrowLeft, User, Lock, LogOut, CheckCircle, Monitor, ShieldAlert } from 'lucide-react';
import type { User as UserType } from '../types';

interface Props {
    user: UserType;
    flash?: { success?: string; warning?: string };
}

export default function Profile({ user, flash }: Props) {
    const [sessions,setSessions]=useState<any[]>([]); const [sessionMsg,setSessionMsg]=useState(''); const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
    const loadSessions=async()=>{try{const r=await fetch('/sessions',{headers:{Accept:'application/json'}});const d=await r.json();if(r.ok)setSessions(d.sessions||[])}catch{}};
    useEffect(()=>{void loadSessions()},[]);
    const logoutSession=async(id:string)=>{const r=await fetch(`/sessions/${id}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}});const d=await r.json();setSessionMsg(d.message||'Session updated.');void loadSessions()};
    const logoutOthers=async()=>{const r=await fetch('/sessions/others',{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}});const d=await r.json();setSessionMsg(d.message||'Other sessions logged out.');void loadSessions()};
    const infoForm = useForm({
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
    });

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submitInfo = (e: FormEvent) => {
        e.preventDefault();
        infoForm.patch('/profile');
    };

    const submitPassword = (e: FormEvent) => {
        e.preventDefault();
        passwordForm.patch('/profile/password', {
            onSuccess: () => passwordForm.reset(),
        });
    };

    const initials = user.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();

    return (
        <div className="profile-page">
            {/* Top bar */}
            <header className="profile-topbar">
                <Link href="/" className="profile-back-btn">
                    <ArrowLeft size={16} />
                    <span>Back to Dashboard</span>
                </Link>
                <div className="profile-topbar-brand">
                    <img src="/logo.svg" alt="SMVS" onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }} />
                    <span>SMVS Storage</span>
                </div>
                <button className="logout-btn" onClick={() => router.post('/logout')} title="Sign out">
                    <LogOut size={16} />
                    <span>Logout</span>
                </button>
            </header>

            <div className="profile-body">
                {/* Avatar + name hero */}
                <div className="profile-hero">
                    <div className="profile-avatar-lg">{initials}</div>
                    <div>
                        <h1 className="profile-hero-name">{user.name}</h1>
                        <span className="profile-hero-role">{user.role.replace('-', ' ')}</span>
                    </div>
                </div>

                {/* Flash message */}
                {flash?.warning && <div className="profile-error-banner" style={{marginBottom:16}}><ShieldAlert size={16}/><span>{flash.warning}</span></div>}
                {flash?.success && (
                    <div className="profile-flash">
                        <CheckCircle size={16} />
                        <span>{flash.success}</span>
                    </div>
                )}

                <div className="profile-grid">
                    {/* Personal info */}
                    <div className="profile-card">
                        <h2 className="profile-card-title">
                            <User size={18} />
                            Personal Information
                        </h2>

                        {infoForm.errors.email && (
                            <div className="profile-error-banner">{infoForm.errors.email}</div>
                        )}

                        <form onSubmit={submitInfo} className="profile-form">
                            <div className="profile-field">
                                <label htmlFor="name">Full name</label>
                                <input
                                    id="name"
                                    type="text"
                                    value={infoForm.data.name}
                                    onChange={e => infoForm.setData('name', e.target.value)}
                                />
                                {infoForm.errors.name && <span className="profile-field-error">{infoForm.errors.name}</span>}
                            </div>

                            <div className="profile-field">
                                <label htmlFor="email">Email address</label>
                                <input
                                    id="email"
                                    type="email"
                                    value={infoForm.data.email}
                                    onChange={e => infoForm.setData('email', e.target.value)}
                                />
                                {infoForm.errors.email && <span className="profile-field-error">{infoForm.errors.email}</span>}
                            </div>

                            <div className="profile-field">
                                <label htmlFor="phone">
                                    Phone <span className="profile-optional">(optional)</span>
                                </label>
                                <input
                                    id="phone"
                                    type="tel"
                                    value={infoForm.data.phone}
                                    onChange={e => infoForm.setData('phone', e.target.value)}
                                    placeholder="e.g. +44 7700 900000"
                                />
                                {infoForm.errors.phone && <span className="profile-field-error">{infoForm.errors.phone}</span>}
                            </div>

                            <div className="profile-field profile-field-static">
                                <label>Role</label>
                                <span className="profile-role-badge">{user.role.replace('-', ' ')}</span>
                            </div>

                            <button
                                type="submit"
                                className="profile-save-btn"
                                disabled={infoForm.processing}
                            >
                                {infoForm.processing ? 'Saving…' : 'Save changes'}
                            </button>
                        </form>
                    </div>

                    {/* Change password */}
                    <div className="profile-card">
                        <h2 className="profile-card-title">
                            <Lock size={18} />
                            Change Password
                        </h2>

                        <form onSubmit={submitPassword} className="profile-form">
                            <div className="profile-field">
                                <label htmlFor="current_password">Current password</label>
                                <input
                                    id="current_password"
                                    type="password"
                                    value={passwordForm.data.current_password}
                                    onChange={e => passwordForm.setData('current_password', e.target.value)}
                                    autoComplete="current-password"
                                />
                                {passwordForm.errors.current_password && (
                                    <span className="profile-field-error">{passwordForm.errors.current_password}</span>
                                )}
                            </div>

                            <div className="profile-field">
                                <label htmlFor="new_password">New password</label>
                                <input
                                    id="new_password"
                                    type="password"
                                    value={passwordForm.data.password}
                                    onChange={e => passwordForm.setData('password', e.target.value)}
                                    placeholder="Min. 8 characters"
                                    autoComplete="new-password"
                                />
                                {passwordForm.errors.password && (
                                    <span className="profile-field-error">{passwordForm.errors.password}</span>
                                )}
                            </div>

                            <div className="profile-field">
                                <label htmlFor="password_confirmation">Confirm new password</label>
                                <input
                                    id="password_confirmation"
                                    type="password"
                                    value={passwordForm.data.password_confirmation}
                                    onChange={e => passwordForm.setData('password_confirmation', e.target.value)}
                                    autoComplete="new-password"
                                />
                            </div>

                            <button
                                type="submit"
                                className="profile-save-btn"
                                disabled={passwordForm.processing}
                            >
                                {passwordForm.processing ? 'Updating…' : 'Update password'}
                            </button>
                        </form>
                    </div>
                    <div className="profile-card" style={{gridColumn:'1 / -1'}}><h2 className="profile-card-title"><Monitor size={18}/> Active Sessions</h2><p style={{fontSize:12,color:'var(--text-secondary)'}}>Review browser/device sessions and revoke any session you no longer recognize.</p>{sessionMsg&&<div className="profile-flash" style={{marginTop:10}}>{sessionMsg}</div>}<div style={{display:'grid',gap:8,marginTop:12}}>{sessions.map(s=><div key={s.id} style={{display:'flex',justifyContent:'space-between',gap:12,alignItems:'center',border:'1px solid var(--border-color)',borderRadius:8,padding:10}}><div><b>{s.current?'Current session':'Other session'}</b><div style={{fontSize:11,color:'var(--text-secondary)'}}>{s.ip_address||'Unknown IP'} · {s.last_activity?new Date(s.last_activity).toLocaleString():'-'}</div><div style={{fontSize:10,color:'var(--text-muted)',maxWidth:700,overflow:'hidden',textOverflow:'ellipsis'}}>{s.user_agent||'Unknown browser'}</div></div>{!s.current&&<button className="btn btn-secondary" onClick={()=>logoutSession(s.id)}>Logout</button>}</div>)}</div><button className="btn btn-secondary" style={{marginTop:10}} onClick={logoutOthers}>Logout All Other Sessions</button></div>
                </div>
            </div>
        </div>
    );
}
