import { DownloadCloud, Trash2, Layers3 } from 'lucide-react';
import { useTx } from '../../i18n';

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
    const { t, tc } = useTx();
    return (
        <div className={`floating-action-bar${selectedCount > 0 ? ' visible' : ''}`}>
            <div className="selected-count-label">{tc('actionbar.selected', selectedCount)}</div>
            <div className="bar-actions">
                <button className="btn btn-secondary" onClick={onClear}>{t('actionbar.clear')}</button>
                {canBulkEdit&&<button className="btn btn-secondary" onClick={onBulkEdit}><Layers3 size={16}/> {t('actionbar.bulk_edit')}</button>}
                <button className="btn btn-primary" onClick={onDownload} title={selectedCount === 1 ? t('actionbar.download_one') : t('actionbar.download_many',{count:selectedCount})}>
                    <DownloadCloud size={16} /> {selectedCount === 1 ? t('actionbar.download') : t('actionbar.download_zip')}
                </button>
                {canDelete && <button className="btn btn-secondary" onClick={onDelete} style={{ borderColor: 'var(--color-danger-light)', color: 'var(--color-danger)', backgroundColor: 'var(--color-danger-light)' }}><Trash2 size={16} /> {t('actionbar.delete')}</button>}
            </div>
        </div>
    );
}
