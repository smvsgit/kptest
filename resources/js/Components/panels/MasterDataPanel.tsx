import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Database, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import type { MasterDataMap, MasterDataType, MasterDataValue } from '../../types';

interface Props {
    masterData: MasterDataMap;
    onSuccess: (msg: string) => void;
    onError: (msg: string) => void;
}

/** Each master type gets its own form, because they do not share the same fields. */
interface TypeSpec {
    type: MasterDataType;
    label: string;
    /** Parent type this value hangs under, when the list is hierarchical. */
    parent: MasterDataType | null;
    parentLabel: string;
    help: string;
    placeholder: string;
}

const SPECS: TypeSpec[] = [
    { type: 'country', label: 'Country', parent: null, parentLabel: '', help: 'Top of the location hierarchy. States hang under a country.', placeholder: 'e.g. India' },
    { type: 'state', label: 'State', parent: 'country', parentLabel: 'Country', help: 'Belongs to a country. Cities hang under a state.', placeholder: 'e.g. Gujarat' },
    { type: 'city', label: 'City', parent: 'state', parentLabel: 'State', help: 'Belongs to a state. Mandirs hang under a city.', placeholder: 'e.g. Vadodara' },
    { type: 'mandir', label: 'Mandir', parent: 'city', parentLabel: 'City', help: 'Belongs to a city. This is the lowest location level.', placeholder: 'e.g. Atladara Mandir' },
    { type: 'event', label: 'Event / Prasang', parent: null, parentLabel: '', help: 'Controlled list of events and prasangs used when tagging media.', placeholder: 'e.g. Annakut' },
    { type: 'person', label: 'Guruji / Person', parent: null, parentLabel: '', help: 'Controlled list of people that media can be attributed to.', placeholder: 'e.g. Guruji' },
    { type: 'language', label: 'Language', parent: null, parentLabel: '', help: 'Spoken or written language of the media content.', placeholder: 'e.g. Gujarati' },
    { type: 'media_type', label: 'Media Type', parent: null, parentLabel: '', help: 'Editorial classification, separate from the technical file type.', placeholder: 'e.g. Pravachan' },
];

const blankDraft = { name: '', code: '', parent: '', aliases: '' };

export default function MasterDataPanel({ masterData, onSuccess, onError }: Props) {
    const [active, setActive] = useState<MasterDataType>('country');
    const [draft, setDraft] = useState({ ...blankDraft });
    const [query, setQuery] = useState('');
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editDraft, setEditDraft] = useState({ ...blankDraft });

    const spec = SPECS.find(s => s.type === active)!;
    const values = useMemo(() => masterData[active] || [], [masterData, active]);
    const parentValues = useMemo(
        () => (spec.parent ? (masterData[spec.parent] || []).filter(v => v.is_active) : []),
        [masterData, spec.parent],
    );

    const parentName = (row: MasterDataValue) => {
        if (!row.parent_id || !spec.parent) return null;
        return (masterData[spec.parent] || []).find(v => v.id === row.parent_id)?.name ?? `#${row.parent_id}`;
    };

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return values;
        return values.filter(v =>
            v.name.toLowerCase().includes(q)
            || (v.code || '').toLowerCase().includes(q)
            || (v.aliases || []).some(a => a.toLowerCase().includes(q))
            || (parentName(v) || '').toLowerCase().includes(q));
    }, [values, query, masterData, spec.parent]);

    const switchType = (type: MasterDataType) => {
        setActive(type);
        setDraft({ ...blankDraft });
        setQuery('');
        setEditingId(null);
    };

    const firstError = (errors: Record<string, string>) => String(Object.values(errors)[0] || '');

    const create = () => {
        if (!draft.name.trim()) return onError(`Enter a ${spec.label.toLowerCase()} name.`);
        if (spec.parent && !draft.parent) return onError(`Select the ${spec.parentLabel.toLowerCase()} this ${spec.label.toLowerCase()} belongs to.`);

        router.post('/settings/master-data', {
            type: active,
            name: draft.name.trim(),
            code: draft.code.trim() || null,
            parent_id: draft.parent ? Number(draft.parent) : null,
            aliases: draft.aliases.split(',').map(x => x.trim()).filter(Boolean),
            is_active: true,
        }, {
            preserveScroll: true,
            onSuccess: () => { onSuccess(`Added ${spec.label} “${draft.name.trim()}”.`); setDraft({ ...blankDraft }); },
            onError: e => onError(firstError(e) || `Could not add this ${spec.label.toLowerCase()}.`),
        });
    };

    const startEdit = (row: MasterDataValue) => {
        setEditingId(row.id);
        setEditDraft({
            name: row.name,
            code: row.code || '',
            parent: row.parent_id ? String(row.parent_id) : '',
            aliases: (row.aliases || []).join(', '),
        });
    };

    const saveEdit = (row: MasterDataValue) => {
        if (!editDraft.name.trim()) return onError('Name cannot be empty.');
        router.patch(`/settings/master-data/${row.id}`, {
            name: editDraft.name.trim(),
            code: editDraft.code.trim() || null,
            parent_id: editDraft.parent ? Number(editDraft.parent) : null,
            aliases: editDraft.aliases.split(',').map(x => x.trim()).filter(Boolean),
            is_active: row.is_active,
            sort_order: row.sort_order || 0,
        }, {
            preserveScroll: true,
            onSuccess: () => { onSuccess(`Updated “${editDraft.name.trim()}”.`); setEditingId(null); },
            onError: e => onError(firstError(e) || 'Could not update this value.'),
        });
    };

    const toggle = (row: MasterDataValue) => router.patch(`/settings/master-data/${row.id}`, {
        name: row.name, code: row.code, parent_id: row.parent_id,
        aliases: row.aliases || [], is_active: !row.is_active, sort_order: row.sort_order || 0,
    }, {
        preserveScroll: true,
        onSuccess: () => onSuccess(`${row.name} ${row.is_active ? 'deactivated' : 'activated'}.`),
        onError: e => onError(firstError(e) || 'Could not update this value.'),
    });

    const remove = (row: MasterDataValue) => {
        if (!confirm(`Delete ${spec.label.toLowerCase()} “${row.name}”? Values already used by media cannot be deleted.`)) return;
        router.delete(`/settings/master-data/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => onSuccess(`Deleted “${row.name}”.`),
            onError: e => onError(firstError(e) || 'Could not delete this value.'),
        });
    };

    return (
        <div className="settings-card">
            <h2 className="settings-card-title"><Database size={18} /> Master Data Management</h2>
            <div className="admin-guide">
                Each master list has its own form below. <b>Country → State → City → Mandir</b> are hierarchical,
                so a parent must be chosen. <b>Event, Guruji/Person, Language</b> and <b>Media Type</b> are flat
                controlled lists. Deactivate a value instead of deleting it once media already uses it.
            </div>

            <div className="master-type-tabs">
                {SPECS.map(s => (
                    <button
                        key={s.type}
                        className={`master-type-tab${s.type === active ? ' active' : ''}`}
                        onClick={() => switchType(s.type)}
                    >
                        {s.label}
                        <span className="master-type-count">{(masterData[s.type] || []).length}</span>
                    </button>
                ))}
            </div>

            <div className="master-form">
                <div className="setting-help" style={{ marginBottom: 10 }}>{spec.help}</div>
                <div className="master-form-grid">
                    {spec.parent && (
                        <div className="form-group">
                            <label className="form-label">{spec.parentLabel} *</label>
                            <select className="form-select" value={draft.parent} onChange={e => setDraft({ ...draft, parent: e.target.value })}>
                                <option value="">Select {spec.parentLabel.toLowerCase()}…</option>
                                {parentValues.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                            </select>
                            {parentValues.length === 0 && (
                                <div className="setting-help" style={{ marginTop: 5 }}>
                                    Add a {spec.parentLabel.toLowerCase()} first.
                                </div>
                            )}
                        </div>
                    )}
                    <div className="form-group">
                        <label className="form-label">{spec.label} name *</label>
                        <input className="form-input" placeholder={spec.placeholder} value={draft.name}
                            onChange={e => setDraft({ ...draft, name: e.target.value })}
                            onKeyDown={e => e.key === 'Enter' && create()} />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Code</label>
                        <input className="form-input" placeholder="Optional short code" value={draft.code}
                            onChange={e => setDraft({ ...draft, code: e.target.value })} />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Aliases</label>
                        <input className="form-input" placeholder="Comma separated, used by search" value={draft.aliases}
                            onChange={e => setDraft({ ...draft, aliases: e.target.value })} />
                    </div>
                </div>
                <button className="btn btn-primary" onClick={create} disabled={Boolean(spec.parent) && parentValues.length === 0}>
                    <Plus size={14} /> Add {spec.label}
                </button>
            </div>

            <div className="master-list-head">
                <div className="category-search-wrapper" style={{ padding: 0, flex: 1 }}>
                    <Search size={13} className="category-search-icon" style={{ left: 9 }} />
                    <input className="category-search-input" placeholder={`Search ${spec.label.toLowerCase()} values…`}
                        value={query} onChange={e => setQuery(e.target.value)} aria-label={`Search ${spec.label} values`} />
                </div>
                <span className="setting-help">{visible.length} of {values.length}</span>
            </div>

            <div className="master-list">
                {values.length === 0 && <div className="setting-help" style={{ padding: '12px 2px' }}>No {spec.label.toLowerCase()} values yet.</div>}
                {values.length > 0 && visible.length === 0 && <div className="setting-help" style={{ padding: '12px 2px' }}>Nothing matches “{query}”.</div>}

                {visible.map(row => editingId === row.id ? (
                    <div className="setting-row" key={row.id}>
                        <div className="master-form-grid" style={{ flex: 1 }}>
                            {spec.parent && (
                                <select className="form-select" value={editDraft.parent} onChange={e => setEditDraft({ ...editDraft, parent: e.target.value })}>
                                    <option value="">Select {spec.parentLabel.toLowerCase()}…</option>
                                    {parentValues.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                </select>
                            )}
                            <input className="form-input" value={editDraft.name} onChange={e => setEditDraft({ ...editDraft, name: e.target.value })} />
                            <input className="form-input" placeholder="Code" value={editDraft.code} onChange={e => setEditDraft({ ...editDraft, code: e.target.value })} />
                            <input className="form-input" placeholder="Aliases" value={editDraft.aliases} onChange={e => setEditDraft({ ...editDraft, aliases: e.target.value })} />
                        </div>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button className="btn btn-primary" onClick={() => saveEdit(row)}><Check size={13} /> Save</button>
                            <button className="btn btn-secondary" onClick={() => setEditingId(null)}><X size={13} /></button>
                        </div>
                    </div>
                ) : (
                    <div className="setting-row" key={row.id}>
                        <div>
                            <div className="setting-title">
                                {row.name}
                                {row.code && <code style={{ marginLeft: 6 }}>{row.code}</code>}
                                {!row.is_active && <span className="master-inactive-pill">Inactive</span>}
                            </div>
                            <div className="setting-help">
                                {parentName(row) && <>{spec.parentLabel}: {parentName(row)} · </>}
                                {(row.aliases || []).length ? `Aliases: ${(row.aliases || []).join(', ')}` : 'No aliases'}
                            </div>
                        </div>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button className="btn btn-secondary" title={`Edit ${row.name}`} onClick={() => startEdit(row)}><Pencil size={12} /></button>
                            <button className="btn btn-secondary" onClick={() => toggle(row)}>{row.is_active ? 'Deactivate' : 'Activate'}</button>
                            <button className="queue-remove-btn" title={`Delete ${row.name}`} onClick={() => remove(row)}><Trash2 size={14} /></button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
