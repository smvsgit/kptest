import { useEffect, useState } from 'react';
import { Copy, KeyRound, Plug, Trash2 } from 'lucide-react';
import { getCsrf } from '../../upload';
import { useT } from '../../i18n';

interface TokenRow {
    id: number; name: string; owner: string | null; hint: string;
    last_used_at: string | null; last_used_ip: string | null;
    expires_at: string | null; revoked: boolean;
}

interface Props {
    toolCount: number;
    onSuccess: (message: string) => void;
    onError: (message: string) => void;
}

async function send(url: string, method = 'GET', body?: unknown) {
    const r = await fetch(url, {
        method,
        headers: { 'X-CSRF-TOKEN': getCsrf(), Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join('; ') || `HTTP ${r.status}`);
    return data;
}

/**
 * Connecting a client to the portal over MCP. (Review F06.)
 *
 * The token is the whole of the setup: the endpoint is fixed, and a token
 * carries the person it was minted for. It is shown once, here, and after that
 * only its first characters, so two tokens can be told apart without either
 * being readable.
 */
export default function McpAccessPanel(p: Props) {
    const t = useT();
    const [endpoint, setEndpoint] = useState('');
    const [tokens, setTokens] = useState<TokenRow[]>([]);
    const [name, setName] = useState('');
    const [days, setDays] = useState('');
    const [fresh, setFresh] = useState('');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        try {
            const data = await send('/settings/mcp/tokens');
            setEndpoint(data.endpoint);
            setTokens(data.tokens || []);
        } catch (e) { p.onError(e instanceof Error ? e.message : ''); }
    };
    useEffect(() => { void load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const create = async () => {
        if (!name.trim()) return p.onError(t('settings.mcp_token_name'));
        setBusy(true);
        try {
            const data = await send('/settings/mcp/tokens', 'POST', {
                name: name.trim(),
                ...(days.trim() ? { expires_in_days: Number(days) } : {}),
            });
            setFresh(data.token);
            setName(''); setDays('');
            p.onSuccess(data.message);
            await load();
        } catch (e) { p.onError(e instanceof Error ? e.message : ''); }
        finally { setBusy(false); }
    };

    const revoke = async (row: TokenRow) => {
        try {
            const data = await send('/settings/mcp/tokens/' + row.id, 'DELETE');
            p.onSuccess(data.message);
            await load();
        } catch (e) { p.onError(e instanceof Error ? e.message : ''); }
    };

    const copy = (value: string) => { void navigator.clipboard?.writeText(value); };

    return <div className="settings-card">
        <h2 className="settings-card-title"><Plug size={18}/> {t('settings.mcp_title')}</h2>
        <div className="admin-guide">{t('settings.mcp_guide')}</div>

        <div className="settings-grid" style={{ marginTop: 12 }}>
            <div className="form-group">
                <label className="form-label">{t('settings.mcp_endpoint')}</label>
                <div style={{ display: 'flex', gap: 6 }}>
                    <input className="form-input" readOnly value={endpoint}/>
                    <button className="btn btn-secondary" onClick={() => copy(endpoint)} title={t('settings.mcp_endpoint')}><Copy size={14}/></button>
                </div>
            </div>
            <div className="form-group">
                <label className="form-label">{t('settings.mcp_transport')}</label>
                <div className="setting-help" style={{ marginTop: 6 }}>{t('settings.mcp_transport_value')}</div>
            </div>
        </div>
        <div className="setting-help">{t('settings.mcp_tools_count', { count: p.toolCount })}</div>

        <div className="settings-grid" style={{ marginTop: 14 }}>
            <div className="form-group">
                <label className="form-label">{t('settings.mcp_token_name')}</label>
                <input className="form-input" value={name} onChange={e => setName(e.target.value)} placeholder="Claude desktop"/>
            </div>
            <div className="form-group">
                <label className="form-label">{t('settings.mcp_token_days')}</label>
                <input className="form-input" type="number" min={1} max={730} value={days} onChange={e => setDays(e.target.value)}/>
            </div>
        </div>
        <button className="btn btn-primary" style={{ marginTop: 10 }} disabled={busy} onClick={create}>
            <KeyRound size={14}/> {t('settings.mcp_token_create')}
        </button>

        {fresh && <div className="admin-guide" style={{ marginTop: 12 }}>
            <b>{t('settings.mcp_token_once')}</b>
            <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
                <input className="form-input" readOnly value={fresh} onFocus={e => e.currentTarget.select()}/>
                <button className="btn btn-secondary" onClick={() => copy(fresh)}><Copy size={14}/></button>
            </div>
        </div>}

        <div style={{ marginTop: 16, display: 'grid', gap: 8 }}>
            {!tokens.length && <div className="setting-help">{t('settings.mcp_token_none')}</div>}
            {tokens.map(row => <div className="setting-row" key={row.id}>
                <div>
                    <b>{row.name}</b> <code style={{ fontSize: 10 }}>{row.hint}</code>
                    {row.revoked && <span className="access-policy-badge" style={{ marginLeft: 6 }}>{t('settings.mcp_revoked')}</span>}
                    <div className="setting-help">
                        {row.owner}
                        {' · '}
                        {row.last_used_at
                            ? `${t('settings.mcp_last_used')} ${new Date(row.last_used_at).toLocaleString()}${row.last_used_ip ? ' · ' + row.last_used_ip : ''}`
                            : t('settings.mcp_never_used')}
                    </div>
                </div>
                {!row.revoked && <button className="icon-btn danger" title={t('settings.mcp_token_revoke')} onClick={() => revoke(row)}>
                    <Trash2 size={15}/></button>}
            </div>)}
        </div>
    </div>;
}
