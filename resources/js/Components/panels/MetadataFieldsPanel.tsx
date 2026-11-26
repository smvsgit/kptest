import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Lock, Plus, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { MetadataFieldDefinition, MetadataFieldInput, MediaFileType } from '../../types';
import { getCsrf } from '../../upload';
import { useT } from '../../i18n';

interface Props {
    fields: MetadataFieldDefinition[];
    onSuccess: (message: string) => void;
    onError: (message: string) => void;
}

const INPUT_TYPES: MetadataFieldInput[] = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];
const MEDIA_TYPES: MediaFileType[] = ['image', 'video', 'audio', 'document'];

type Draft = {
    label: string; help: string; input_type: MetadataFieldInput; applies_to: MediaFileType[];
    options: string; default_value: string; is_required: boolean; is_locked: boolean;
    is_active: boolean; sort_order: number;
};

const blank = (): Draft => ({
    label: '', help: '', input_type: 'text', applies_to: [], options: '',
    default_value: '', is_required: false, is_locked: false, is_active: true, sort_order: 0,
});

const draftOf = (field: MetadataFieldDefinition): Draft => ({
    label: field.label, help: field.help || '', input_type: field.input_type,
    applies_to: field.applies_to || [], options: (field.options || []).join('\n'),
    default_value: field.default_value || '', is_required: field.is_required,
    is_locked: field.is_locked, is_active: field.is_active, sort_order: field.sort_order,
});

async function send(url: string, method: string, body?: unknown) {
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
 * The administrator's field builder. (Review F09b.)
 *
 * Fields defined here are asked for in Describe. Retiring one takes it off the
 * forms and keeps every value already recorded; deleting is only offered while
 * nothing has ever been filed under it, and the server refuses otherwise.
 */
export default function MetadataFieldsPanel(p: Props) {
    const t = useT();
    const [draft, setDraft] = useState<Draft>(blank());
    const [editing, setEditing] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    const payload = (d: Draft) => ({
        ...d,
        options: d.input_type === 'select' ? d.options.split('\n').map(x => x.trim()).filter(Boolean) : [],
    });

    const save = async () => {
        if (!draft.label.trim()) return p.onError(t('settings.field_label'));
        setBusy(true);
        try {
            const data = editing
                ? await send(`/settings/metadata/fields/${editing}`, 'PATCH', payload(draft))
                : await send('/settings/metadata/fields', 'POST', payload(draft));
            p.onSuccess(data.message);
            setDraft(blank());
            setEditing(null);
            router.reload({ only: ['metadataFields'] });
        } catch (e) {
            p.onError(e instanceof Error ? e.message : '');
        } finally { setBusy(false); }
    };

    const retire = async (field: MetadataFieldDefinition, active: boolean) => {
        try {
            const data = await send(`/settings/metadata/fields/${field.id}`, 'PATCH', { ...payload(draftOf(field)), is_active: active });
            p.onSuccess(data.message);
            router.reload({ only: ['metadataFields'] });
        } catch (e) { p.onError(e instanceof Error ? e.message : ''); }
    };

    const remove = async (field: MetadataFieldDefinition) => {
        try {
            const data = await send(`/settings/metadata/fields/${field.id}`, 'DELETE');
            p.onSuccess(data.message);
            router.reload({ only: ['metadataFields'] });
        } catch (e) { p.onError(e instanceof Error ? e.message : ''); }
    };

    return <div className="settings-card">
        <h2 className="settings-card-title"><SlidersHorizontal size={18}/> {t('settings.fields_title')}</h2>
        <div className="admin-guide">{t('settings.fields_guide')}</div>
        <div className="setting-help" style={{ marginTop: 6 }}>{t('settings.field_system_note')}</div>

        <div className="settings-grid" style={{ marginTop: 12 }}>
            <div className="form-group"><label className="form-label">{t('settings.field_label')}</label>
                <input className="form-input" value={draft.label} onChange={e => setDraft({ ...draft, label: e.target.value })}/></div>
            <div className="form-group"><label className="form-label">{t('settings.field_type')}</label>
                <select className="form-select" value={draft.input_type} onChange={e => setDraft({ ...draft, input_type: e.target.value as MetadataFieldInput })}>
                    {INPUT_TYPES.map(type => <option key={type} value={type}>{t('fields.type_' + type)}</option>)}
                </select></div>
            <div className="form-group"><label className="form-label">{t('settings.field_help')}</label>
                <input className="form-input" value={draft.help} onChange={e => setDraft({ ...draft, help: e.target.value })}/></div>
            <div className="form-group"><label className="form-label">{t('settings.field_default')}</label>
                <input className="form-input" value={draft.default_value} onChange={e => setDraft({ ...draft, default_value: e.target.value })}/></div>
            <div className="form-group"><label className="form-label">{t('settings.field_order')}</label>
                <input className="form-input" type="number" min={0} max={999} value={draft.sort_order}
                    onChange={e => setDraft({ ...draft, sort_order: Number(e.target.value) || 0 })}/></div>
        </div>

        <div className="form-group" style={{ marginTop: 8 }}>
            <span className="form-label">{t('settings.field_applies')}</span>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                {MEDIA_TYPES.map(type => <label className="check-row" key={type}>
                    <input type="checkbox" checked={draft.applies_to.includes(type)}
                        onChange={e => setDraft({ ...draft, applies_to: e.target.checked
                            ? [...draft.applies_to, type]
                            : draft.applies_to.filter(x => x !== type) })}/>
                    {t('filters.' + (type === 'image' ? 'images' : type === 'video' ? 'videos' : type === 'audio' ? 'audio' : 'documents'))}
                </label>)}
                {!draft.applies_to.length && <span className="setting-help">{t('settings.field_applies_all')}</span>}
            </div>
        </div>

        {draft.input_type === 'select' && <div className="form-group">
            <label className="form-label">{t('settings.field_options')}</label>
            <textarea className="form-input" rows={3} value={draft.options} style={{ resize: 'vertical' }}
                onChange={e => setDraft({ ...draft, options: e.target.value })}/>
        </div>}

        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', marginTop: 6 }}>
            <label className="check-row"><input type="checkbox" checked={draft.is_required}
                onChange={e => setDraft({ ...draft, is_required: e.target.checked })}/>{t('settings.field_required')}</label>
            <label className="check-row"><input type="checkbox" checked={draft.is_locked}
                onChange={e => setDraft({ ...draft, is_locked: e.target.checked })}/>{t('settings.field_locked')}</label>
        </div>

        <button className="btn btn-primary" style={{ marginTop: 12 }} disabled={busy} onClick={save}>
            <Plus size={14}/> {editing ? t('settings.field_save') : t('settings.field_add')}
        </button>
        {editing && <button className="btn btn-secondary" style={{ marginTop: 12, marginLeft: 8 }}
            onClick={() => { setEditing(null); setDraft(blank()); }}>{t('common.cancel')}</button>}

        <div style={{ marginTop: 16, display: 'grid', gap: 8 }}>
            {!p.fields.length && <div className="setting-help">{t('settings.fields_none')}</div>}
            {p.fields.map(field => <div className="setting-row" key={field.id}>
                <div>
                    <b>{field.label}</b> <code style={{ fontSize: 10 }}>{field.key}</code>
                    {field.is_locked && <Lock size={12} style={{ verticalAlign: -2, marginLeft: 6 }}/>}
                    {!field.is_active && <span className="access-policy-badge" style={{ marginLeft: 6 }}>{t('settings.field_retired')}</span>}
                    <div className="setting-help">
                        {t('fields.type_' + field.input_type)}
                        {' · '}{field.applies_to?.length ? field.applies_to.join(', ') : t('settings.field_applies_all')}
                        {field.is_required ? ' · ' + t('settings.field_required') : ''}
                    </div>
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <button className="btn btn-secondary" onClick={() => { setEditing(field.id); setDraft(draftOf(field)); }}>
                        {t('settings.field_save')}</button>
                    <button className="btn btn-secondary" onClick={() => retire(field, !field.is_active)}>
                        {field.is_active ? t('settings.field_retire') : t('settings.field_restore')}</button>
                    <button className="icon-btn danger" title={t('settings.field_delete')} onClick={() => remove(field)}>
                        <Trash2 size={15}/></button>
                </div>
            </div>)}
        </div>
    </div>;
}
