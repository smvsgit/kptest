import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { X, FolderTree, FolderPlus } from 'lucide-react';
import type { Category } from '../../types';
import { useT } from '../../i18n';

export type CategoryModalMode = 'add-category' | 'add-subcategory' | 'edit-category' | 'edit-subcategory';

interface Props {
    mode: CategoryModalMode;
    categories: Category[];
    /** Parent main category: preselected for add-subcategory, target for edit-category. */
    categoryId?: number | null;
    /** Subcategory being renamed/moved in edit-subcategory mode. */
    subcategoryId?: number | null;
    onClose: () => void;
    onSuccess: (msg: string) => void;
    onError: (msg: string) => void;
}

// Catalogue keys, not finished strings: a module-scope constant cannot call a
// hook, so the title is translated where it is rendered.
const TITLE_KEYS: Record<CategoryModalMode, string> = {
    'add-category': 'catmodal.add_category',
    'add-subcategory': 'catmodal.add_subcategory',
    'edit-category': 'catmodal.rename_category',
    'edit-subcategory': 'catmodal.edit_subcategory',
};

export default function CategoryModal({ mode, categories, categoryId, subcategoryId, onClose, onSuccess, onError }: Props) {
    const t = useT();
    const editingCategory = mode === 'edit-category' ? categories.find(c => c.id === categoryId) ?? null : null;
    const editingSub = mode === 'edit-subcategory'
        ? categories.flatMap(c => c.subcategories).find(s => s.id === subcategoryId) ?? null
        : null;

    // Sub-category flows always resolve a parent main category first; the name is typed after that.
    const [parentId, setParentId] = useState<number | ''>(
        editingSub ? editingSub.category_id : (categoryId ?? '')
    );
    const [name, setName] = useState(editingCategory?.name ?? editingSub?.name ?? '');
    const [loading, setLoading] = useState(false);
    const nameRef = useRef<HTMLInputElement>(null);

    const needsParent = mode === 'add-subcategory' || mode === 'edit-subcategory';
    const isSubMode = needsParent;
    const parent = categories.find(c => c.id === parentId) ?? null;

    const done = (msg: string) => () => onSuccess(msg);
    const fail = (fallback: string) => (errors: Record<string, string>) => {
        setLoading(false);
        onError(errors?.name || errors?.category_id || fallback);
    };

    const submit = () => {
        const trimmed = name.trim();
        if (needsParent && parentId === '') {
            onError(t('catmodal.need_parent'));
            return;
        }
        if (!trimmed) {
            onError(isSubMode ? t('catmodal.need_subcategory_name') : t('catmodal.need_category_name'));
            return;
        }
        setLoading(true);
        const opts = { preserveState: true, preserveScroll: true };

        if (mode === 'add-category') {
            router.post('/categories', { name: trimmed }, {
                ...opts,
                onSuccess: done(`Created main category “${trimmed}”`),
                onError: fail(t('catmodal.create_failed')),
            });
        } else if (mode === 'edit-category') {
            router.put(`/categories/${categoryId}`, { name: trimmed }, {
                ...opts,
                onSuccess: done(`Renamed category to “${trimmed}”`),
                onError: fail(t('catmodal.rename_failed')),
            });
        } else if (mode === 'add-subcategory') {
            router.post('/subcategories', { category_id: parentId, name: trimmed }, {
                ...opts,
                onSuccess: done(`Created sub-category “${trimmed}” under ${parent?.name}`),
                onError: fail(t('catmodal.create_sub_failed')),
            });
        } else {
            router.put(`/subcategories/${subcategoryId}`, { category_id: parentId, name: trimmed }, {
                ...opts,
                onSuccess: done(`Updated sub-category “${trimmed}”`),
                onError: fail(t('catmodal.update_sub_failed')),
            });
        }
    };

    const existingSiblings = parent?.subcategories ?? [];

    return (
        <div className="modal-overlay active" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="modal-container" style={{ gridTemplateColumns: '1fr', maxWidth: 460, padding: 24 }}>
                <div className="modal-header" style={{ marginBottom: 16 }}>
                    <h2 className="modal-title" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        {isSubMode ? <FolderPlus size={18} /> : <FolderTree size={18} />}
                        {t(TITLE_KEYS[mode])}
                    </h2>
                    <button className="modal-close-btn" onClick={onClose}><X size={20} /></button>
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {needsParent && (
                        <div className="form-group">
                            <label className="form-label">{t('catmodal.step1')}</label>
                            <select
                                className="form-input"
                                value={parentId}
                                onChange={e => {
                                    const next = e.target.value === '' ? '' : Number(e.target.value);
                                    setParentId(next);
                                    // Step 2 becomes usable as soon as a parent is chosen, so move focus there.
                                    if (next !== '') setTimeout(() => nameRef.current?.focus(), 0);
                                }}
                                autoFocus={parentId === ''}
                            >
                                <option value="">Select main category…</option>
                                {categories.map(c => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                            {categories.length === 0 && (
                                <div style={{ fontSize: 11, color: 'var(--color-danger, #e04545)', marginTop: 6 }}>
                                    No main category exists yet. Add a main category first, then add its sub-categories.
                                </div>
                            )}
                        </div>
                    )}

                    <div className="form-group">
                        <label className="form-label">
                            {needsParent ? t('catmodal.step2') : t('catmodal.category_name')}
                        </label>
                        <input
                            ref={nameRef}
                            type="text"
                            className="form-input"
                            value={name}
                            onChange={e => setName(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && submit()}
                            placeholder={needsParent ? 'e.g. Satsang Sabha' : 'e.g. Events'}
                            disabled={needsParent && parentId === ''}
                            autoFocus={!needsParent || parentId !== ''}
                        />
                        {needsParent && parent && existingSiblings.length > 0 && (
                            <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 6, lineHeight: 1.5 }}>
                                Existing in {parent.name}: {existingSiblings.map(s => s.name).join(', ')}
                            </div>
                        )}
                    </div>

                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                        <button className="btn btn-secondary" onClick={onClose}>{t('common.cancel')}</button>
                        <button
                            className="btn btn-primary"
                            onClick={submit}
                            disabled={loading || (needsParent && parentId === '')}
                        >
                            {mode.startsWith('edit') ? t('catmodal.save') : t('catmodal.create')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
