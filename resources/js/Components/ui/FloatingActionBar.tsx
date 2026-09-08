import { DownloadCloud, Trash2, Layers3 } from 'lucide-react';

interface Props {
    selectedCount: number;
    canDelete: boolean;
    canBulkEdit: boolean;
    onClear: () => void;
    onDownload: () => void;
    onBulkEdit: () => void;
    onDelete: () => void;
}

export default function FloatingActionBar({ selectedCount, canDelete, canBulkEdit, onClear, onDownload, onBulkEdit, onDelete }: Props) {
    return (
        <div className={`floating-action-bar${selectedCount > 0 ? ' visible' : ''}`}>
            <div className="selected-count-label">Selected: <span>{selectedCount}</span> files</div>
            <div className="bar-actions">
                <button className="btn btn-secondary" onClick={onClear}>Clear Selection</button>
                {canBulkEdit&&<button className="btn btn-secondary" onClick={onBulkEdit}><Layers3 size={16}/> Bulk Edit</button>}
                <button className="btn btn-primary" onClick={onDownload}><DownloadCloud size={16} /> Download ZIP</button>
                {canDelete && <button className="btn btn-secondary" onClick={onDelete} style={{ borderColor: 'var(--color-danger-light)', color: 'var(--color-danger)', backgroundColor: 'var(--color-danger-light)' }}><Trash2 size={16} /> Delete</button>}
            </div>
        </div>
    );
}
