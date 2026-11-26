import { useEffect, useMemo, useState } from 'react';
import { ChevronRight, FolderOpen, Loader2, UploadCloud, X } from 'lucide-react';
import { getCsrf } from '../../upload';
import { useT } from '../../i18n';

interface FolderNode { id:number; parent_id:number|null; name:string; depth:number; file_count:number; is_active:boolean }
interface Props {
    mode:'files'|'folder';
    onClose:()=>void;
    /** Hand the chosen files to the shared upload tray, bound to this folder. */
    onChosen:(files:File[], folderId:number|null, message:string)=>void;
    onError:(message:string)=>void;
}

/**
 * Where is this going?
 *
 * Browse has no folder context of its own, so an upload started from there has
 * to be told where to land. The review (F04) asked for the destination to be
 * required or shown — "do not silently upload into an unrelated root" — so the
 * file picker stays disabled until a folder is chosen, and the full path of
 * the choice is spelled out above the button.
 *
 * The department root is a legitimate destination, but it has to be picked
 * deliberately like any other.
 */
export default function UploadDestinationModal({ mode, onClose, onChosen, onError }: Props) {
    const t = useT();
    const [departments, setDepartments] = useState<{id:number;name:string}[]>([]);
    const [departmentId, setDepartmentId] = useState<number|null>(null);
    const [departmentName, setDepartmentName] = useState('');
    const [folders, setFolders] = useState<FolderNode[]>([]);
    const [chosen, setChosen] = useState<number|null|undefined>(undefined); // undefined = nothing picked yet
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState('');

    const load = async (department?: number|null) => {
        setLoading(true);
        try {
            const url = '/folders' + (department ? '?department_id=' + department : '');
            const r = await fetch(url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrf() } });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.message || `HTTP ${r.status}`);
            setFolders((data.folders || []).filter((f: FolderNode) => f.is_active));
            setDepartments(data.departments || []);
            setDepartmentId(data.department_id ?? null);
            const named = (data.departments || []).find((d: {id:number}) => d.id === data.department_id);
            setDepartmentName(named?.name || '');
        } catch (e) {
            onError(e instanceof Error ? e.message : t('upload.failed'));
        } finally {
            setLoading(false);
        }
    };
    useEffect(() => { void load(); /* once */ }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const byId = useMemo(() => new Map(folders.map(f => [f.id, f])), [folders]);
    const pathOf = (id: number|null): string => {
        const root = departmentName || t('folders.department');
        if (id === null) return root;
        const names: string[] = [];
        let node = byId.get(id);
        let guard = 0;
        while (node && guard++ < 12) {
            names.unshift(node.name);
            node = node.parent_id ? byId.get(node.parent_id) : undefined;
        }
        return [root, ...names].join(' / ');
    };

    const shown = filter.trim()
        ? folders.filter(f => f.name.toLowerCase().includes(filter.trim().toLowerCase()))
        : folders;

    const picked = chosen !== undefined;

    const pick = (files: FileList|null) => {
        if (!files?.length || !picked) return;
        onChosen(Array.from(files), chosen ?? null, t('upload.sending_to', { path: pathOf(chosen ?? null) }));
        onClose();
    };

    return <div className="modal-overlay active" onClick={e => e.target === e.currentTarget && onClose()}>
        <div className="modal-container" style={{ maxWidth: 620, width: '94vw', display: 'block' }}>
            <div className="modal-info-pane" style={{ width: '100%', maxHeight: '82vh', overflow: 'auto' }}>
                <div className="modal-header">
                    <div>
                        <h2 className="modal-title"><UploadCloud size={20}/> {t(mode === 'folder' ? 'upload.choose_folder_title' : 'upload.choose_files_title')}</h2>
                        <p className="page-subtitle">{t('upload.destination_help')}</p>
                    </div>
                    <button className="modal-close-btn" onClick={onClose}><X size={20}/></button>
                </div>

                {departments.length > 1 && <div className="form-group">
                    <span className="form-label">{t('fields.department')}</span>
                    <select className="form-select" value={departmentId ?? ''} onChange={e => {
                        setChosen(undefined);
                        void load(Number(e.target.value));
                    }}>
                        {departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                </div>}

                <div className="form-group">
                    <span className="form-label">{t('upload.destination')}</span>
                    <input className="form-input" value={filter} onChange={e => setFilter(e.target.value)}
                        placeholder={t('folders.search_folders')}/>
                </div>

                <div className="upload-destination-list">
                    {loading && <div className="setting-help"><Loader2 size={14} className="spin"/> {t('common.loading')}</div>}
                    {!loading && <button type="button"
                        className={`upload-destination-row${chosen === null ? ' active' : ''}`}
                        onClick={() => setChosen(null)}>
                        <FolderOpen size={14}/> {departmentName || t('folders.department')} <span className="muted">· {t('upload.department_root')}</span>
                    </button>}
                    {!loading && shown.map(f => <button type="button" key={f.id}
                        className={`upload-destination-row${chosen === f.id ? ' active' : ''}`}
                        style={{ paddingLeft: 12 + (filter.trim() ? 0 : f.depth * 16) }}
                        onClick={() => setChosen(f.id)}>
                        <ChevronRight size={13}/> {f.name} <span className="muted">· {f.file_count}</span>
                    </button>)}
                    {!loading && !shown.length && !filter.trim() && <div className="setting-help">{t('upload.no_folders')}</div>}
                </div>

                <div className="admin-guide" style={{ marginTop: 12 }}>
                    {picked
                        ? <><b>{t('upload.will_go_to')}</b> {pathOf(chosen ?? null)}</>
                        : t('upload.pick_destination_first')}
                </div>

                <label className={`btn btn-primary${picked ? '' : ' disabled'}`} style={{ marginTop: 12, display: 'inline-flex', opacity: picked ? 1 : .5, cursor: picked ? 'pointer' : 'not-allowed' }}>
                    <UploadCloud size={15}/> {t(mode === 'folder' ? 'upload.pick_folder' : 'upload.pick_files')}
                    <input type="file" hidden multiple disabled={!picked}
                        {...(mode === 'folder' ? { webkitdirectory: '', directory: '' } as Record<string, string> : {})}
                        onChange={e => { pick(e.target.files); e.currentTarget.value = ''; }}/>
                </label>
            </div>
        </div>
    </div>;
}
