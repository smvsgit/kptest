import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { X, FolderTree, FolderPlus } from 'lucide-react';
import type { Category } from '../../types';

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

const TITLES: Record<CategoryModalMode, string> = {
    'add-category': 'Add Main Category',
    'add-subcategory': 'Add Sub-Category',
    'edit-category': 'Rename Main Category',
    'edit-subcategory': 'Edit Sub-Category',
};

export default function CategoryModal({ mode, categories, categoryId, subcategoryId, onClose, onSuccess, onError }: Props) {
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
            onError('Please select the main category first.');
            return;
        }
        if (!trimmed) {
            onError(isSubMode ? 'Please enter a sub-category name.' : 'Please enter a category name.');
            return;
        }
        setLoading(true);
        const opts = { preserveState: true, preserveScroll: true };

        if (mode === 'add-category') {
            router.post('/categories', { name: trimmed }, {
                ...opts,
                onSuccess: done(`Created main category “${trimmed}”`),
                onError: fail('Failed to create category.'),
            });
        } else if (mode === 'edit-category') {
            router.put(`/categories/${categoryId}`, { name: trimmed }, {
                ...opts,
                onSuccess: done(`Renamed category to “${trimmed}”`),
                onError: fail('Failed to rename category.'),
            });
        } else if (mode === 'add-subcategory') {
            router.post('/subcategories', { category_id: parentId, name: trimmed }, {
                ...opts,
                onSuccess: done(`Created sub-category “${trimmed}” under ${parent?.name}`),
                onError: fail('Failed to create sub-category.'),
            });
        } else {
            router.put(`/subcategories/${subcategoryId}`, { category_id: parentId, name: trimmed }, {
                ...opts,
                onSuccess: done(`Updated sub-category “${trimmed}”`),
                onError: fail('Failed to update sub-category.'),
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
                        {TITLES[mode]}
                    </h2>
                    <button className="modal-close-btn" onClick={onClose}><X size={20} /></button>
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {needsParent && (
                        <div className="form-group">
                            <label className="form-label">Step 1 — Main Category</label>
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
                            {needsParent ? 'Step 2 — Sub-Category Name' : 'Category Name'}
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
                        <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
                        <button
                            className="btn btn-primary"
                            onClick={submit}
                            disabled={loading || (needsParent && parentId === '')}
                        >
                            {mode.startsWith('edit') ? 'Save Changes' : 'Create'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
