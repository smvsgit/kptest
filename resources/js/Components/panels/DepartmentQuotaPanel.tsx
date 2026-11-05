import { useState } from 'react';
import { router } from '@inertiajs/react';
import { HardDrive } from 'lucide-react';
import { useT } from '../../i18n';
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

export default function DepartmentQuotaPanel({ departmentStorage, defaultQuotaGb, warningPercent, onSuccess, onError }: Props) {
    const t = useT();
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
            if (!Number.isFinite(parsed) || parsed < 0) return onError(t('quota.positive_number'));
            departments[id] = parsed;
        }
        if (!Number.isFinite(defaults.default_quota_gb) || defaults.default_quota_gb < 0) return onError(t('quota.default_positive'));
        if (defaults.warning_percent < 1 || defaults.warning_percent > 100) return onError(t('quota.threshold_range'));

        setSaving(true);
        router.patch('/settings/feature-completion', {
            section: 'storage_quotas',
            settings: { ...defaults, departments },
        }, {
            preserveScroll: true,
            onSuccess: () => onSuccess(t('quota.saved')),
            onError: e => onError(String(Object.values(e)[0] || t('quota.save_failed'))),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="settings-card">
            <h2 className="settings-card-title"><HardDrive size={18} /> {t('quota.title')}</h2>
            <div className="admin-guide">
                {t('quota.guide')}
            </div>

            <div className="settings-grid" style={{ marginTop: 12 }}>
                <div className="form-group">
                    <label className="form-label">{t('quota.default_quota')}</label>
                    <input className="form-input" type="number" min={0} step="0.1" value={defaults.default_quota_gb}
                        onChange={e => setDefaults({ ...defaults, default_quota_gb: Number(e.target.value) })} />
                </div>
                <div className="form-group">
                    <label className="form-label">{t('quota.warning_threshold')}</label>
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
                                    {d.is_system && <span className="setting-help">{t('quota.system_department')}</span>}
                                    {!d.is_active && <span className="setting-help">{t('quota.inactive')}</span>}
                                </div>
                                <span className={`quota-pill ${d.state}`}>{t('quota.state_' + d.state)}</span>
                            </div>

                            <div className="quota-meter" role="img"
                                aria-label={d.quota_bytes > 0
                                    ? t('quota.meter_label_of', { name: d.name, used: formatBytes(d.used_bytes), quota: formatBytes(d.quota_bytes) })
                                    : t('quota.meter_label', { name: d.name, used: formatBytes(d.used_bytes) })}>
                                <i className={d.state} style={{ width: `${barPercent}%` }} />
                            </div>

                            <div className="quota-row-foot">
                                <span className="setting-help">
                                    {t('quota.used_line', { used: formatBytes(d.used_bytes) })}
                                    {d.quota_bytes > 0
                                        ? t('quota.used_of', { quota: formatBytes(d.quota_bytes), percent: String(d.percent_used) })
                                        : t('quota.no_limit')}
                                </span>
                                <label className="quota-input">
                                    <span className="setting-help">{t('quota.quota_gb')}</span>
                                    <input className="form-input" type="number" min={0} step="0.1"
                                        placeholder={t('quota.default_placeholder', { value: defaults.default_quota_gb })}
                                        value={override}
                                        onChange={e => setOverrides({ ...overrides, [String(d.id)]: e.target.value })} />
                                </label>
                            </div>
                        </div>
                    );
                })}
            </div>

            <button className="btn btn-primary" style={{ marginTop: 12 }} onClick={save} disabled={saving}>
                {t('quota.save')}
            </button>
        </div>
    );
}
