import { useState } from 'react';
import { router } from '@inertiajs/react';
import { HardDrive } from 'lucide-react';
import type { DepartmentStorage } from '../../types';

interface Props {
    departmentStorage: DepartmentStorage[];
    defaultQuotaGb: number;
    warningPercent: number;
    onSuccess: (msg: string) => void;
    onError: (msg: string) => void;
}

function formatBytes(bytes: number) {
    if (!bytes) return '0 B';
    const k = 1024, units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(Math.max(bytes, 1)) / Math.log(k)));
    return `${(bytes / Math.pow(k, i)).toFixed(i ? 1 : 0)} ${units[i]}`;
}

const STATE_LABEL: Record<DepartmentStorage['state'], string> = {
    unlimited: 'Unlimited', ok: 'Within quota', warning: 'Near quota', full: 'Quota reached',
};

export default function DepartmentQuotaPanel({ departmentStorage, defaultQuotaGb, warningPercent, onSuccess, onError }: Props) {
    const [defaults, setDefaults] = useState({ default_quota_gb: defaultQuotaGb, warning_percent: warningPercent });
    const [overrides, setOverrides] = useState<Record<string, string>>(() =>
        Object.fromEntries(departmentStorage.filter(d => !d.uses_default).map(d => [String(d.id), String(d.quota_gb)])));
    const [saving, setSaving] = useState(false);

    const save = () => {
        const departments: Record<string, number> = {};
        for (const [id, raw] of Object.entries(overrides)) {
            const value = raw.trim();
            if (value === '') continue;            // blank means "follow the default"
            const parsed = Number(value);
            if (!Number.isFinite(parsed) || parsed < 0) return onError('Quota values must be zero or a positive number of GB.');
            departments[id] = parsed;
        }
        if (!Number.isFinite(defaults.default_quota_gb) || defaults.default_quota_gb < 0) return onError('Default quota must be zero or a positive number of GB.');
        if (defaults.warning_percent < 1 || defaults.warning_percent > 100) return onError('Warning threshold must be between 1 and 100.');

        setSaving(true);
        router.patch('/settings/feature-completion', {
            section: 'storage_quotas',
            settings: { ...defaults, departments },
        }, {
            preserveScroll: true,
            onSuccess: () => onSuccess('Storage quotas saved.'),
            onError: e => onError(String(Object.values(e)[0] || 'Could not save storage quotas.')),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="settings-card">
            <h2 className="settings-card-title"><HardDrive size={18} /> Department Storage Quotas</h2>
            <div className="admin-guide">
                Leave a department blank to follow the default quota. <b>0 GB means unlimited.</b> Usage counts
                stored bytes including Recycle Bin items and historical versions, and is the same figure upload
                enforcement uses — so what you see here is what is enforced.
            </div>

            <div className="settings-grid" style={{ marginTop: 12 }}>
                <div className="form-group">
                    <label className="form-label">Default quota (GB)</label>
                    <input className="form-input" type="number" min={0} step="0.1" value={defaults.default_quota_gb}
                        onChange={e => setDefaults({ ...defaults, default_quota_gb: Number(e.target.value) })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Warning threshold (%)</label>
                    <input className="form-input" type="number" min={1} max={100} value={defaults.warning_percent}
                        onChange={e => setDefaults({ ...defaults, warning_percent: Number(e.target.value) })} />
                </div>
            </div>

            <div className="quota-list">
                {departmentStorage.map(d => {
                    const override = overrides[String(d.id)] ?? '';
                    const barPercent = d.percent_used === null ? 0 : Math.min(100, d.percent_used);
                    return (
                        <div className="quota-row" key={d.id}>
                            <div className="quota-row-head">
                                <div>
                                    <b>{d.name}</b>
                                    {d.is_system && <span className="setting-help"> · System department</span>}
                                    {!d.is_active && <span className="setting-help"> · Inactive</span>}
                                </div>
                                <span className={`quota-pill ${d.state}`}>{STATE_LABEL[d.state]}</span>
                            </div>

                            <div className="quota-meter" role="img"
                                aria-label={`${d.name} is using ${formatBytes(d.used_bytes)}${d.quota_bytes > 0 ? ` of ${formatBytes(d.quota_bytes)}` : ''}`}>
                                <i className={d.state} style={{ width: `${barPercent}%` }} />
                            </div>

                            <div className="quota-row-foot">
                                <span className="setting-help">
                                    {formatBytes(d.used_bytes)} used
                                    {d.quota_bytes > 0
                                        ? ` of ${formatBytes(d.quota_bytes)} (${d.percent_used}%)`
                                        : ' · no limit set'}
                                </span>
                                <label className="quota-input">
                                    <span className="setting-help">Quota (GB)</span>
                                    <input className="form-input" type="number" min={0} step="0.1"
                                        placeholder={`Default (${defaults.default_quota_gb})`}
                                        value={override}
                                        onChange={e => setOverrides({ ...overrides, [String(d.id)]: e.target.value })} />
                                </label>
                            </div>
                        </div>
                    );
                })}
            </div>

            <button className="btn btn-primary" style={{ marginTop: 12 }} onClick={save} disabled={saving}>
                Save Storage Quotas
            </button>
        </div>
    );
}
