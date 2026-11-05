import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Database, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import type { Category, MasterDataMap, MasterDataType, MasterDataValue } from '../../types';
import { useT } from '../../i18n';

interface Props {
    masterData: MasterDataMap;
    /** Categories live in their own tables; they are surfaced here as master lists. */
    categories: Category[];
    onSuccess: (msg: string) => void;
    onError: (msg: string) => void;
}

/**
 * Category and Sub-category are presented alongside the controlled master lists
 * so every controlled value is administered in one place, but they keep their
 * own tables and routes - only the screen is shared.
 */
type TabKey = MasterDataType | 'category' | 'subcategory';

/** Each master type gets its own form, because they do not share the same fields. */
interface TypeSpec {
    type: TabKey;
    /** Parent type this value hangs under, when the list is hierarchical. */
    parent: TabKey | null;
    /** Categories have no code, aliases or active flag, so those inputs are hidden. */
    extras?: boolean;
}

/**
 * Only the shape of each list lives here. Its label, help text and example
 * placeholder are catalogue keys (master.label_*, master.help_*, master.ph_*),
 * so the screen reads in whichever language the user has chosen.
 */
const SPECS: TypeSpec[] = [
    { type: 'category', parent: null, extras: false },
    { type: 'subcategory', parent: 'category', extras: false },
    { type: 'country', parent: null },
    { type: 'state', parent: 'country' },
    { type: 'city', parent: 'state' },
    { type: 'mandir', parent: 'city' },
    { type: 'event', parent: null },
    { type: 'person', parent: null },
    { type: 'language', parent: null },
    { type: 'media_type', parent: null },
];

const blankDraft = { name: '', code: '', parent: '', aliases: '' };

export default function MasterDataPanel({ masterData, categories, onSuccess, onError }: Props) {
    const t = useT();
    const [active, setActive] = useState<TabKey>('category');
    const [draft, setDraft] = useState({ ...blankDraft });
    const [query, setQuery] = useState('');
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editDraft, setEditDraft] = useState({ ...blankDraft });

    const spec = SPECS.find(s => s.type === active)!;
    const label = t('master.label_' + spec.type);
    const parentLabel = spec.parent ? t('master.label_' + spec.parent) : '';

    /**
     * Categories and sub-categories come from their own tables, so they are
     * mapped into the same row shape the master lists render with. Fields they
     * do not have (code, aliases, sort order) stay empty and their inputs are
     * hidden via spec.extras.
     */
    const rowsFor = (key: TabKey): MasterDataValue[] => {
        if (key === 'category') {
            return categories.map(c => ({ id: c.id, type: 'category' as MasterDataType, name: c.name, code: null, parent_id: null, aliases: [], is_active: true, sort_order: 0 }));
        }
        if (key === 'subcategory') {
            return categories.flatMap(c => c.subcategories.map(sub => ({ id: sub.id, type: 'subcategory' as MasterDataType, name: sub.name, code: null, parent_id: c.id, aliases: [], is_active: true, sort_order: 0 })));
        }
        return masterData[key as MasterDataType] || [];
    };

    const values = useMemo(() => rowsFor(active), [masterData, categories, active]);
    const parentValues = useMemo(
        () => (spec.parent ? rowsFor(spec.parent).filter(v => v.is_active) : []),
        [masterData, categories, spec.parent],
    );

    const parentName = (row: MasterDataValue) => {
        if (!row.parent_id || !spec.parent) return null;
        return rowsFor(spec.parent).find(v => v.id === row.parent_id)?.name ?? `#${row.parent_id}`;
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

    const switchType = (type: TabKey) => {
        setActive(type);
        setDraft({ ...blankDraft });
        setQuery('');
        setEditingId(null);
    };

    const firstError = (errors: Record<string, string>) => String(Object.values(errors)[0] || '');

    const create = () => {
        if (!draft.name.trim()) return onError(t('master.enter_name', { label }));
        if (spec.parent && !draft.parent) return onError(t('master.select_parent_error', { parent: parentLabel, label }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => { onSuccess(t('master.added', { label, name: draft.name.trim() })); setDraft({ ...blankDraft }); },
            onError: (e: Record<string, string>) => onError(firstError(e) || t('master.add_failed', { label })),
        };

        if (active === 'category') {
            router.post('/categories', { name: draft.name.trim() }, opts);
        } else if (active === 'subcategory') {
            router.post('/subcategories', { category_id: Number(draft.parent), name: draft.name.trim() }, opts);
        } else {
            router.post('/settings/master-data', {
                type: active,
                name: draft.name.trim(),
                code: draft.code.trim() || null,
                parent_id: draft.parent ? Number(draft.parent) : null,
                aliases: draft.aliases.split(',').map(x => x.trim()).filter(Boolean),
                is_active: true,
            }, opts);
        }
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
        if (!editDraft.name.trim()) return onError(t('master.name_empty'));
        const opts = {
            preserveScroll: true,
            onSuccess: () => { onSuccess(t('master.updated', { name: editDraft.name.trim() })); setEditingId(null); },
            onError: (e: Record<string, string>) => onError(firstError(e) || t('master.update_failed')),
        };

        if (active === 'category') {
            router.put(`/categories/${row.id}`, { name: editDraft.name.trim() }, opts);
        } else if (active === 'subcategory') {
            router.put(`/subcategories/${row.id}`, { category_id: Number(editDraft.parent) || row.parent_id, name: editDraft.name.trim() }, opts);
        } else {
            router.patch(`/settings/master-data/${row.id}`, {
                name: editDraft.name.trim(),
                code: editDraft.code.trim() || null,
                parent_id: editDraft.parent ? Number(editDraft.parent) : null,
                aliases: editDraft.aliases.split(',').map(x => x.trim()).filter(Boolean),
                is_active: row.is_active,
                sort_order: row.sort_order || 0,
            }, opts);
        }
    };

    const toggle = (row: MasterDataValue) => router.patch(`/settings/master-data/${row.id}`, {
        name: row.name, code: row.code, parent_id: row.parent_id,
        aliases: row.aliases || [], is_active: !row.is_active, sort_order: row.sort_order || 0,
    }, {
        preserveScroll: true,
        onSuccess: () => onSuccess(row.is_active ? t('master.toggled_off', { name: row.name }) : t('master.toggled_on', { name: row.name })),
        onError: e => onError(firstError(e) || t('master.update_failed')),
    });

    const remove = (row: MasterDataValue) => {
        const warning = active === 'category'
            ? t('master.delete_category', { name: row.name })
            : active === 'subcategory'
                ? t('master.delete_subcategory', { name: row.name })
                : t('master.delete_value', { label, name: row.name });
        if (!confirm(warning)) return;

        const url = active === 'category' ? `/categories/${row.id}`
            : active === 'subcategory' ? `/subcategories/${row.id}`
                : `/settings/master-data/${row.id}`;

        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => onSuccess(t('master.deleted', { name: row.name })),
            onError: e => onError(firstError(e) || t('master.delete_failed')),
        });
    };

    return (
        <div className="settings-card">
            <h2 className="settings-card-title"><Database size={18} /> {t('master.title')}</h2>
            <div className="admin-guide">
                {t('master.guide')}
            </div>

            <div className="master-type-tabs">
                {SPECS.map(s => (
                    <button
                        key={s.type}
                        className={`master-type-tab${s.type === active ? ' active' : ''}`}
                        onClick={() => switchType(s.type)}
                    >
                        {t('master.label_' + s.type)}
                        <span className="master-type-count">{rowsFor(s.type).length}</span>
                    </button>
                ))}
            </div>

            <div className="master-form">
                <div className="setting-help" style={{ marginBottom: 10 }}>{t('master.help_' + spec.type)}</div>
                <div className="master-form-grid">
                    {spec.parent && (
                        <div className="form-group">
                            <label className="form-label">{parentLabel} *</label>
                            <select className="form-select" value={draft.parent} onChange={e => setDraft({ ...draft, parent: e.target.value })}>
                                <option value="">{t('master.select_parent', { parent: parentLabel })}</option>
                                {parentValues.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                            </select>
                            {parentValues.length === 0 && (
                                <div className="setting-help" style={{ marginTop: 5 }}>
                                    {t('master.add_parent_first', { parent: parentLabel })}
                                </div>
                            )}
                        </div>
                    )}
                    <div className="form-group">
                        <label className="form-label">{t('master.name_field', { label })} *</label>
                        <input className="form-input" placeholder={t('master.ph_' + spec.type)} value={draft.name}
                            onChange={e => setDraft({ ...draft, name: e.target.value })}
                            onKeyDown={e => e.key === 'Enter' && create()} />
                    </div>
                    {spec.extras !== false && <>
                        <div className="form-group">
                            <label className="form-label">{t('master.code')}</label>
                            <input className="form-input" placeholder={t('master.code_placeholder')} value={draft.code}
                                onChange={e => setDraft({ ...draft, code: e.target.value })} />
                        </div>
                        <div className="form-group">
                            <label className="form-label">{t('master.aliases')}</label>
                            <input className="form-input" placeholder={t('master.aliases_placeholder')} value={draft.aliases}
                                onChange={e => setDraft({ ...draft, aliases: e.target.value })} />
                        </div>
                    </>}
                </div>
                <button className="btn btn-primary" onClick={create} disabled={Boolean(spec.parent) && parentValues.length === 0}>
                    <Plus size={14} /> {t('master.add_label', { label })}
                </button>
            </div>

            <div className="master-list-head">
                <div className="category-search-wrapper" style={{ padding: 0, flex: 1 }}>
                    <Search size={13} className="category-search-icon" style={{ left: 9 }} />
                    <input className="category-search-input" placeholder={t('master.search_values', { label })}
                        value={query} onChange={e => setQuery(e.target.value)} aria-label={t('master.search_values_aria', { label })} />
                </div>
                <span className="setting-help">{t('master.visible_of_total', { visible: visible.length, total: values.length })}</span>
            </div>

            <div className="master-list">
                {values.length === 0 && <div className="setting-help" style={{ padding: '12px 2px' }}>{t('master.no_values', { label })}</div>}
                {values.length > 0 && visible.length === 0 && <div className="setting-help" style={{ padding: '12px 2px' }}>{t('master.nothing_matches', { query })}</div>}

                {visible.map(row => editingId === row.id ? (
                    <div className="setting-row" key={row.id}>
                        <div className="master-form-grid" style={{ flex: 1 }}>
                            {spec.parent && (
                                <select className="form-select" value={editDraft.parent} onChange={e => setEditDraft({ ...editDraft, parent: e.target.value })}>
                                    <option value="">{t('master.select_parent', { parent: parentLabel })}</option>
                                    {parentValues.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                </select>
                            )}
                            <input className="form-input" value={editDraft.name} onChange={e => setEditDraft({ ...editDraft, name: e.target.value })} />
                            {spec.extras !== false && <>
                                <input className="form-input" placeholder={t('master.code')} value={editDraft.code} onChange={e => setEditDraft({ ...editDraft, code: e.target.value })} />
                                <input className="form-input" placeholder={t('master.aliases')} value={editDraft.aliases} onChange={e => setEditDraft({ ...editDraft, aliases: e.target.value })} />
                            </>}
                        </div>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button className="btn btn-primary" onClick={() => saveEdit(row)}><Check size={13} /> {t('common.save')}</button>
                            <button className="btn btn-secondary" onClick={() => setEditingId(null)}><X size={13} /></button>
                        </div>
                    </div>
                ) : (
                    <div className="setting-row" key={row.id}>
                        <div>
                            <div className="setting-title">
                                {row.name}
                                {row.code && <code style={{ marginLeft: 6 }}>{row.code}</code>}
                                {!row.is_active && <span className="master-inactive-pill">{t('master.inactive')}</span>}
                            </div>
                            <div className="setting-help">
                                {parentName(row) && t('master.parent_line', { parent: parentLabel, name: parentName(row)! })}
                                {spec.extras !== false && <>{parentName(row) ? ' · ' : ''}{(row.aliases || []).length ? t('master.aliases_line', { list: (row.aliases || []).join(', ') }) : t('master.no_aliases')}</>}
                            </div>
                        </div>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button className="btn btn-secondary" title={t('settings.edit_item', { name: row.name })} onClick={() => startEdit(row)}><Pencil size={12} /></button>
                            {spec.extras !== false && <button className="btn btn-secondary" onClick={() => toggle(row)}>{row.is_active ? t('master.deactivate') : t('master.activate')}</button>}
                            <button className="queue-remove-btn" title={t('settings.delete_item', { name: row.name })} onClick={() => remove(row)}><Trash2 size={14} /></button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
