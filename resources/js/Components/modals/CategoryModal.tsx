import { useState } from 'react';
import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { Category } from '../../types';

interface Props {
    parentId: number | null;
    categories: Category[];
    onClose: () => void;
    onSuccess: (msg: string) => void;
    onError: (msg: string) => void;
}

export default function CategoryModal({ parentId, categories, onClose, onSuccess, onError }: Props) {
    const [name, setName] = useState('');
    const [loading, setLoading] = useState(false);

    const parent = parentId !== null ? categories.find(c => c.id === parentId) : null;
    const title = parent ? `Add folder to ${parent.name}` : 'Create Main Category';

    const submit = () => {
        if (!name.trim()) { onError('Please enter a category name.'); return; }
        setLoading(true);

        if (parentId !== null) {
            router.post(`/categories/${parentId}/subcategories`, { name }, {
                preserveState: true,
                onSuccess: () => onSuccess(`Created sub-folder "${name}" under ${parent?.name}`),
                onError: () => { setLoading(false); onError('Failed to create sub-folder.'); },
            });
        } else {
            router.post('/categories', { name }, {
                preserveState: true,
                onSuccess: () => onSuccess(`Created category "${name}"`),
                onError: () => { setLoading(false); onError('Failed to create category.'); },
            });
        }
    };

    return (
        <div className="modal-overlay active" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="modal-container" style={{ gridTemplateColumns: '1fr', maxWidth: 440, padding: 24 }}>
                <div className="modal-header" style={{ marginBottom: 16 }}>
                    <h2 className="modal-title">{title}</h2>
                    <button className="modal-close-btn" onClick={onClose}><X size={20} /></button>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    <div className="form-group">
                        <label className="form-label">Category Name</label>
                        <input type="text" className="form-input" value={name}
                            onChange={e => setName(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && submit()}
                            placeholder="e.g. Shooting Locations"
                            autoFocus />
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                        <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
                        <button className="btn btn-primary" onClick={submit} disabled={loading}>Create</button>
                    </div>
                </div>
            </div>
        </div>
    );
}
