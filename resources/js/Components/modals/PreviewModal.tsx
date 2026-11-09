import { useEffect, useState } from 'react';
import { X, Download, Trash2, Headphones, LockKeyhole, ShieldAlert, History, UploadCloud, RotateCcw, AlertTriangle, Link2, RefreshCw, Plus, Maximize2 } from 'lucide-react';
import type { AccessLevel, AccessPolicy, MediaFile, MediaFileVersion, Category, UploadSettings, SourceConnection, MediaSourceItem } from '../../types';
import ImageViewer from './ImageViewer';
import { useT } from '../../i18n';

interface Props {
    file: MediaFile;
    categories: Category[];
    canDelete: boolean;
    uploadSettings: UploadSettings;
    sourceConnections: SourceConnection[];
    lifecycleSettings?: { transitions?: Record<string, string[]> };
    onClose: () => void;
    onDelete: (id: number) => void;
    onDownload: (id: number) => void;
    onRequestAccess: (file: MediaFile, reason: string, level: AccessLevel) => Promise<boolean>;
    onUpdatePolicy: (file: MediaFile, policy: AccessPolicy, downloadAllowed: boolean) => Promise<boolean>;
    onLifecycleChanged: () => void;
}

function formatBytes(bytes: number) {
    if (!bytes) return '0 B';
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

export default function PreviewModal({ file, categories, canDelete, uploadSettings, sourceConnections, lifecycleSettings, onClose, onDelete, onDownload, onRequestAccess, onUpdatePolicy, onLifecycleChanged }: Props) {
    const t = useT();
    const cat = categories.find(c => c.id === file.category_id);
    const sub = cat?.subcategories.find(s => s.id === file.subcategory_id);
    // Full-size viewer: the inline preview is deliberately capped so the metadata
    // stays visible, so opening the original is a separate overlay.
    const [zoomOpen, setZoomOpen] = useState(false);
    const [inlineStatus, setInlineStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [reason, setReason] = useState('');
    const [level, setLevel] = useState<AccessLevel>(file.can_preview && !file.can_download ? 'download' : 'view');
    const [requesting, setRequesting] = useState(false);
    const [policy, setPolicy] = useState<AccessPolicy>(file.access_policy);
    const [downloadAllowed, setDownloadAllowed] = useState(file.download_allowed);
    const [savingPolicy, setSavingPolicy] = useState(false);
    const [versions, setVersions] = useState<MediaFileVersion[]>([]);
    const [versionLoading, setVersionLoading] = useState(false);
    const [newVersionFile, setNewVersionFile] = useState<File|null>(null);
    const [changeNote, setChangeNote] = useState('');
    const [lifecycleBusy, setLifecycleBusy] = useState(false);
    const [versionProgress, setVersionProgress] = useState('');
    const [lifecycleMessage, setLifecycleMessage] = useState('');
    const [targetStatus, setTargetStatus] = useState('');
    const [statusNote, setStatusNote] = useState('');
    const [sources,setSources]=useState<MediaSourceItem[]>(file.source_items||[]);
    const [sourceBusy,setSourceBusy]=useState(false);
    const [sourceForm,setSourceForm]=useState({type:'youtube',label:'',locator:'',external_id:'',integration_connection_id:'',is_primary:false,is_enabled:true,repair_note:''});
    const fallbackTransitions:Record<string,string[]>={active:['draft','review','archived'],draft:['review','archived'],review:['draft','approved','archived'],approved:['review','published','archived'],published:['archived'],archived:['draft','published'],inactive:['draft'],broken:['draft']};
    const currentStatus=(file.asset_status||'draft').toLowerCase();
    const allowedTransitions=(lifecycleSettings?.transitions?.[currentStatus]||fallbackTransitions[currentStatus]||[]).filter(x=>['draft','review','approved','published','archived'].includes(x));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const loadVersions = async () => {
        if (!file.can_manage_lifecycle) return;
        setVersionLoading(true);
        try {
            const r = await fetch(`/files/${file.id}/versions`, {headers:{Accept:'application/json'}});
            const data = await r.json();
            if (!r.ok) throw new Error(data.message || t('details.versions_load_failed'));
            setVersions(data.versions || []);
        } catch (e) { setLifecycleMessage(e instanceof Error ? e.message : t('details.versions_load_failed')); }
        finally { setVersionLoading(false); }
    };
    useEffect(() => { void loadVersions(); }, [file.id, file.can_manage_lifecycle]);

    const uploadVersion = async () => {
        if (!newVersionFile || changeNote.trim().length < 3) return;
        setLifecycleBusy(true); setLifecycleMessage(''); setVersionProgress(t('details.preparing_upload'));
        const chunkSize=Math.max(5,Math.min(20,uploadSettings.chunk_size_mb||10))*1024*1024; const totalChunks=Math.max(1,Math.ceil(newVersionFile.size/chunkSize)); const uploadId=crypto.randomUUID();
        try {
            for(let i=0;i<totalChunks;i++){
                const start=i*chunkSize,end=Math.min(start+chunkSize,newVersionFile.size); const fd=new FormData();
                fd.append('upload_id',uploadId);fd.append('chunk_index',String(i));fd.append('total_chunks',String(totalChunks));fd.append('total_size',String(newVersionFile.size));fd.append('is_last_chunk',i===totalChunks-1?'true':'false');fd.append('filename',newVersionFile.name);fd.append('chunk',newVersionFile.slice(start,end),`chunk-${i}`);
                if(i===totalChunks-1)fd.append('change_note',changeNote.trim());
                const r=await fetch(`/files/${file.id}/version-chunks`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'},body:fd}); const data=await r.json(); if(!r.ok)throw new Error(data.message||t('details.chunk_failed',{index:i+1}));
                const sent=end; setVersionProgress(`${formatBytes(sent)} / ${formatBytes(newVersionFile.size)} · ${Math.round((sent/newVersionFile.size)*100)}%`);
                if(i===totalChunks-1)setLifecycleMessage(data.message||t('details.version_uploaded'));
            }
            setNewVersionFile(null);setChangeNote('');await loadVersions();onLifecycleChanged();
        } catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.version_upload_failed'));try{await fetch(`/files/${file.id}/version-chunks/${uploadId}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}})}catch{}}
        finally {setLifecycleBusy(false);setVersionProgress('');}
    };

    const restoreVersion = async (version: MediaFileVersion) => {
        if (!confirm(t('details.restore_confirm',{number:version.version_number}))) return;
        setLifecycleBusy(true); setLifecycleMessage('');
        try {
            const r = await fetch(`/files/${file.id}/versions/${version.id}/restore`, {method:'POST', headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({change_note:t('details.restored_from',{number:version.version_number})})});
            const data=await r.json(); if(!r.ok) throw new Error(data.message || t('details.restore_failed'));
            setLifecycleMessage(data.message || t('details.version_restored')); await loadVersions(); onLifecycleChanged();
        } catch(e){ setLifecycleMessage(e instanceof Error ? e.message : t('details.restore_failed')); }
        finally { setLifecycleBusy(false); }
    };

    const transitionLifecycle = async () => {
        if (!targetStatus) return;
        setLifecycleBusy(true); setLifecycleMessage('');
        try {
            const r=await fetch(`/files/${file.id}/lifecycle`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({status:targetStatus,note:statusNote.trim()||null})});
            const data=await r.json();
            if(!r.ok)throw new Error(data.message||Object.values(data.errors||{}).flat().join('; ')||t('details.lifecycle_failed'));
            setLifecycleMessage(data.message||t('details.lifecycle_updated')); setTargetStatus(''); setStatusNote(''); onLifecycleChanged();
        } catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.lifecycle_failed'))} finally{setLifecycleBusy(false)}
    };

    const checkSource = async (source:MediaSourceItem) => { setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}/check`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}}); const d=await r.json(); if(!r.ok)throw new Error(d.message||t('details.source_check_failed')); setLifecycleMessage(t('details.source_status',{status:d.status,message:d.message})); onLifecycleChanged(); } catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.source_check_failed'))} finally{setSourceBusy(false)} };
    const saveSource = async () => { if(!sourceForm.locator.trim())return; setSourceBusy(true); try { const body={...sourceForm,integration_connection_id:sourceForm.integration_connection_id?Number(sourceForm.integration_connection_id):null}; const r=await fetch(`/files/${file.id}/sources`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)}); const d=await r.json(); if(!r.ok)throw new Error(d.message||t('details.source_add_failed')); setLifecycleMessage(d.message||t('details.source_added')); setSourceForm({type:'youtube',label:'',locator:'',external_id:'',integration_connection_id:'',is_primary:false,is_enabled:true,repair_note:''}); onLifecycleChanged(); } catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.source_add_failed'))} finally{setSourceBusy(false)} };
    const repairSource = async (source:MediaSourceItem) => { const locator=prompt(t('details.replace_locator'),source.locator||''); if(locator===null||!locator.trim())return; const note=prompt(t('details.repair_note'),t('details.repair_default'))||''; setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type:source.type,label:source.label,locator,external_id:source.external_id,integration_connection_id:source.integration_connection_id,is_primary:source.is_primary,is_enabled:source.is_enabled,repair_note:note})});const d=await r.json();if(!r.ok)throw new Error(d.message||t('details.repair_failed'));setLifecycleMessage(d.message||t('details.source_updated'));onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.repair_failed'))}finally{setSourceBusy(false)} };
    const toggleSource = async (source:MediaSourceItem) => { setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type:source.type,label:source.label,locator:source.locator,external_id:source.external_id,integration_connection_id:source.integration_connection_id,is_primary:source.is_primary,is_enabled:!source.is_enabled,repair_note:source.repair_note})});const d=await r.json();if(!r.ok)throw new Error(d.message||t('details.status_change_failed'));setLifecycleMessage(source.is_enabled?t('details.source_disabled'):t('details.source_enabled'));onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.status_change_failed'))}finally{setSourceBusy(false)} };
    const removeSource = async (source:MediaSourceItem) => { if(!confirm(t('details.remove_source_confirm')))return;setSourceBusy(true);try{const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.message||t('details.source_remove_failed'));setLifecycleMessage(d.message||t('details.source_removed'));onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:t('details.source_remove_failed'))}finally{setSourceBusy(false)}};

    const submitRequest = async () => {
        if (reason.trim().length < 5) return;
        setRequesting(true);
        const ok = await onRequestAccess(file, reason.trim(), level);
        setRequesting(false);
        if (ok) setReason('');
    };

    const savePolicy = async () => {
        setSavingPolicy(true);
        await onUpdatePolicy(file, policy, downloadAllowed);
        setSavingPolicy(false);
    };

    const imageSrc = file.image_url || file.thumbnail_url || undefined;

    // An image that can be previewed has its own viewer, opened from the card or
    // from the button below, so the dialog shows details only and stays compact.
    const showMediaPane = !(file.type === 'image' && file.can_preview);

    const renderPreview = () => {
        if (!file.can_preview) {
            return <div className="access-locked-panel"><LockKeyhole size={58} /><div><h3 style={{ color: '#fff', marginBottom: 6 }}>{t('details.locked_title')}</h3><p style={{ color: '#cbd5e1', maxWidth: 360 }}>{t('details.locked_body')}</p></div></div>;
        }
        const external=(file.source_items||[]).find(s=>s.is_primary&&s.status==='active'&&s.embed_url)||(file.source_items||[]).find(s=>s.status==='active'&&s.embed_url);
        if (external?.embed_url) return <iframe src={external.embed_url} title={file.name} style={{width:'100%',height:'50vh',border:0}} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowFullScreen/>;
        if (file.type === 'image') {
            // Prefer the generated thumbnail here: the inline pane is small, and
            // loading a multi-megabyte original just to fill it leaves the pane
            // blank for seconds. The original is loaded by the full-size viewer.
            const src = file.thumbnail_url || file.image_url;
            if (!src) return <div className="access-locked-panel"><Link2 size={50}/><p>{t('details.no_local_preview')}</p></div>;
            return <button type="button" className="image-zoom-trigger" onClick={() => setZoomOpen(true)} title={t('details.click_full_size')}>
                {inlineStatus === 'loading' && <span className="image-inline-status"><span className="image-zoom-spinner" aria-hidden="true" />{t('details.loading_preview')}</span>}
                {inlineStatus === 'error' && <span className="image-inline-status">{t('details.preview_unavailable')}</span>}
                <img src={src} alt={file.name}
                    style={{ maxWidth: '100%', maxHeight: '50vh', objectFit: 'contain', display: inlineStatus === 'ready' ? undefined : 'none' }}
                    onLoad={() => setInlineStatus('ready')} onError={() => setInlineStatus('error')} />
                <span className="image-zoom-hint"><Maximize2 size={13}/> {t('details.full_size')}</span>
            </button>;
        }
        if (file.type === 'video') return file.video_url?<video controls autoPlay muted style={{ width: '100%', height: '100%', maxHeight: '50vh' }}><source src={file.video_url} />{t('details.no_video_tag')}</video>:<div className="access-locked-panel"><Link2 size={50}/><p>{t('details.video_external')}</p></div>;
        if (file.type === 'audio') return <div className="modal-audio-player"><div className="audio-preview-icon"><Headphones size={32} /></div><div style={{ textAlign: 'center' }}><h4 style={{ fontWeight: 600, marginBottom: 4 }}>{file.name}</h4><span style={{ fontSize: 11, color: '#94a3b8' }}>{t('details.audio_track')}</span></div><audio controls autoPlay className="audio-element"><source src={file.audio_url || ''} /></audio></div>;
        const ext = file.name.split('.').pop()?.toUpperCase() || 'FILE';
        return <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20, color: '#ffffff', padding: 48 }}><div style={{ width: 72, height: 84, borderRadius: 8, background: 'var(--color-accent)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: '#1b2e2a', gap: 4 }}><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg><span style={{ fontSize: 11, fontWeight: 700 }}>{ext}</span></div><div style={{ textAlign: 'center' }}><h3 style={{ fontWeight: 600, marginBottom: 8 }}>{file.name}</h3><p style={{ fontSize: 12, color: '#94a3b8', marginBottom: 16 }}>{t('details.document_preview')}</p>{file.document_url && <a href={file.document_url} target="_blank" rel="noopener noreferrer" style={{ fontSize: 13, color: 'var(--color-accent)', fontWeight: 600, textDecoration: 'underline' }}>{t('details.open_new_tab')}</a>}</div></div>;
    };

    const na = t('details.not_available');
    const metadata = [
        [t('details.label_category'), cat?.name || t('details.uncategorized')], [t('details.label_subcategory'), sub?.name || t('details.none')],
        [t('details.label_department'), file.department?.name || t('details.unassigned')], [t('details.label_policy'), t('policy.' + file.access_policy)],
        [t('details.label_year'), file.year || na], [t('details.label_country'), file.country?.name || na], [t('details.label_state'), file.state?.name || na], [t('details.label_city'), file.city?.name || na],
        [t('details.label_mandir'), file.mandir?.name || na], [t('details.label_event'), file.event?.name || na], [t('details.label_person'), file.person?.name || na], [t('details.label_language'), file.language?.name || na],
        [t('details.label_size'), formatBytes(file.size)], [t('details.label_resolution'), file.resolution || na], [t('details.label_source_health'), file.source_health_status ? t('health.' + file.source_health_status) : t('details.unknown')], [t('details.label_source'), t('source.' + (file.source_type || 'local'))], [t('details.label_asset_status'), t('status.' + (file.asset_status || 'active'))],
        [t('details.label_version'), `v${file.current_version || 1}`], [t('details.label_roles'), (file.visible_to_roles||[]).length ? (file.visible_to_roles||[]).map(r=>r.name).join(', ') : t('details.policy_only')], [t('details.label_upload_date'), new Date(file.created_at).toLocaleString()], [t('details.label_uploaded_by'), file.uploader?.name || t('details.system')],
    ];

    return <>{zoomOpen && <ImageViewer file={file} onClose={() => setZoomOpen(false)} onDownload={onDownload} />}<div className="modal-overlay active" onClick={e => { if (e.target === e.currentTarget) onClose(); }}><div className={`modal-container${showMediaPane ? '' : ' details-only'}`}>{showMediaPane&&<div className="modal-preview-pane" style={{position:'relative'}}>{renderPreview()}{file.can_preview&&file.watermark_enabled_effective&&<div aria-hidden="true" style={{position:'absolute',inset:0,pointerEvents:'none',display:'flex',alignItems:'center',justifyContent:'center',overflow:'hidden',opacity:.18,fontSize:34,fontWeight:800,transform:'rotate(-28deg)',color:'#fff',textShadow:'0 1px 2px #000'}}>{file.watermark_text||'Karyalay Portal'}</div>}</div>}<div className="modal-info-pane"><div className="modal-header"><div><h2 className="modal-title">{file.name}</h2><div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}><span className={`card-type-badge badge-${file.type}`} style={{ position: 'static', display: 'inline-block' }}>{file.type}</span><span className={`access-policy-badge access-${file.access_policy}`}>{t('policy.' + file.access_policy)}</span>{file.access_request_status && <span className="request-status-pill">{t('details.request_label',{status:file.access_request_status})}</span>}{!showMediaPane&&imageSrc&&<button type="button" className="view-image-pill" onClick={() => setZoomOpen(true)}><Maximize2 size={12}/> {t('details.view_image')}</button>}</div></div><button className="modal-close-btn" onClick={onClose}><X size={20} /></button></div>
        <div className="metadata-grid">{metadata.map(([label, value]) => <div key={String(label)} className="metadata-row"><span className="metadata-label">{label}</span><span className="metadata-value">{value}</span></div>)}</div>
        {file.description&&<div className="form-group"><span className="form-label">{t('details.description')}</span><div style={{fontSize:12,color:'var(--text-secondary)',marginTop:4}}>{file.description}</div></div>}<div className="form-group" style={{ marginBottom: 18 }}><span className="form-label">{t('details.tags')}</span><div className="card-tags" style={{ marginTop: 6 }}>{file.tags.map(tag => <span key={tag} className="card-tag" style={{ fontSize: 11, padding: '4px 8px' }}>{tag}</span>)}</div></div>

        {file.duplicate_of_id && <div style={{border:'1px solid var(--color-warning)',borderRadius:'var(--radius-md)',padding:12,marginBottom:16,background:'rgba(234,179,8,.08)'}}><div style={{display:'flex',gap:8,alignItems:'center',fontWeight:600,fontSize:12}}><AlertTriangle size={16}/> {t('details.duplicate_title')}</div><div style={{fontSize:11,color:'var(--text-secondary)',marginTop:4}}>{t('details.duplicate_body',{id:file.duplicate_of_id})}</div></div>}

        <details className="advanced-sections"><summary><span>{t('details.advanced_summary')}</span></summary><div className="advanced-sections-body">
        <div style={{border:'1px solid var(--border-color)',borderRadius:'var(--radius-md)',padding:14,marginBottom:18}}><h3 style={{fontSize:13,display:'flex',gap:7,alignItems:'center',marginBottom:10}}><Link2 size={16}/> {t('details.sources')} · {(file.source_items||[]).length}</h3><div className="source-list">{(file.source_items||[]).map(src=><div className="source-row" key={src.id}><div className="source-row-main"><span className={`integration-status ${src.status}`}>{src.status}</span><div><div style={{fontSize:11,fontWeight:700}}>{src.label||src.type}{src.is_primary?' · '+t('details.primary'):''}</div><div className="source-meta">{src.connection?.name||src.type} · {t('details.last_check',{when:src.last_checked_at?new Date(src.last_checked_at).toLocaleString():t('details.never')})} · {t('details.last_success',{when:src.last_success_at?new Date(src.last_success_at).toLocaleString():t('details.never')})}</div>{src.last_error&&<div className="source-meta" style={{color:'var(--color-danger)'}}>{src.last_error}</div>}</div></div><div style={{display:'flex',gap:5,flexWrap:'wrap',justifyContent:'flex-end'}}>{src.open_url&&file.can_preview&&<a className="btn btn-secondary" href={src.open_url} target="_blank" rel="noopener noreferrer" style={{padding:'5px 7px',fontSize:10}}>{t('details.open')}</a>}<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>checkSource(src)} style={{padding:'5px 7px',fontSize:10}}><RefreshCw size={12}/> {t('details.retry_check')}</button>{file.can_manage_lifecycle&&<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>toggleSource(src)} style={{padding:'5px 7px',fontSize:10}}>{src.is_enabled?t('details.disable'):t('details.enable')}</button>}{file.can_manage_lifecycle&&<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>repairSource(src)} style={{padding:'5px 7px',fontSize:10}}>{t('details.replace_repair')}</button>}{file.can_manage_lifecycle&&<button className="queue-remove-btn" disabled={sourceBusy} onClick={()=>removeSource(src)}><Trash2 size={13}/></button>}</div></div>)}</div>{file.can_manage_lifecycle&&<div style={{marginTop:12,paddingTop:12,borderTop:'1px solid var(--border-color)'}}><div className="form-label">{t('details.add_source')}</div><div className="settings-grid"><select className="form-select" value={sourceForm.type} onChange={e=>setSourceForm({...sourceForm,type:e.target.value,integration_connection_id:''})}><option value="local">{t('source.local')}</option><option value="nas">{t('source.nas')}</option><option value="google-drive">{t('source.google-drive')}</option><option value="youtube">{t('source.youtube')}</option></select><select className="form-select" value={sourceForm.integration_connection_id} onChange={e=>setSourceForm({...sourceForm,integration_connection_id:e.target.value})}><option value="">{t('details.no_connection')}</option>{sourceConnections.filter(c=>c.type===sourceForm.type).map(c=><option key={c.id} value={c.id}>{c.name} · {c.status}</option>)}</select><input className="form-input" placeholder={t('details.source_label')} value={sourceForm.label} onChange={e=>setSourceForm({...sourceForm,label:e.target.value})}/><input className="form-input" placeholder={t('details.locator')} value={sourceForm.locator} onChange={e=>setSourceForm({...sourceForm,locator:e.target.value})}/></div><label style={{display:'flex',gap:7,alignItems:'center',fontSize:11,marginTop:8}}><input type="checkbox" checked={sourceForm.is_primary} onChange={e=>setSourceForm({...sourceForm,is_primary:e.target.checked})}/> {t('details.make_primary')}</label><button className="btn btn-secondary" disabled={sourceBusy||!sourceForm.locator.trim()} onClick={saveSource} style={{width:'100%',marginTop:8}}><Plus size={13}/> {t('details.add_source_btn')}</button></div>}</div>

        {file.can_manage_lifecycle && <div style={{border:'1px solid var(--border-color)',borderRadius:'var(--radius-md)',padding:14,marginBottom:18}}><h3 style={{fontSize:13,display:'flex',gap:7,alignItems:'center',marginBottom:10}}><ShieldAlert size={16}/> {t('details.lifecycle')}</h3><div className="setting-help" style={{marginBottom:8}}>{t('details.current_status',{status:t('status.' + currentStatus)})}</div>{allowedTransitions.length?<><select className="form-select" value={targetStatus} onChange={e=>setTargetStatus(e.target.value)}><option value="">{t('details.select_next_status')}</option>{allowedTransitions.map(status=><option key={status} value={status}>{t('status.' + status)}</option>)}</select><textarea className="form-input" rows={2} placeholder={t('details.transition_note')} value={statusNote} onChange={e=>setStatusNote(e.target.value)} style={{marginTop:8,resize:'vertical'}}/><button className="btn btn-primary" style={{width:'100%',marginTop:8}} disabled={lifecycleBusy||!targetStatus} onClick={transitionLifecycle}>{lifecycleBusy?t('details.applying'):t('details.apply_transition')}</button></>:<div className="admin-guide">{t('details.no_transition',{status:t('status.' + currentStatus)})}</div>}</div>}

        {file.can_manage_lifecycle && <div style={{border:'1px solid var(--border-color)',borderRadius:'var(--radius-md)',padding:14,marginBottom:18}}><h3 style={{fontSize:13,display:'flex',gap:7,alignItems:'center',marginBottom:10}}><History size={16}/> {t('details.version_history',{version:file.current_version || 1})}</h3>{lifecycleMessage&&<div className="admin-guide" style={{marginBottom:10}}>{lifecycleMessage}</div>}{versionLoading?<div style={{fontSize:12,color:'var(--text-secondary)'}}>{t('details.loading_versions')}</div>:<div style={{display:'grid',gap:7,maxHeight:180,overflowY:'auto'}}>{versions.map(v=><div key={v.id} style={{display:'grid',gridTemplateColumns:'52px 1fr auto',gap:8,alignItems:'center',padding:'8px 9px',border:'1px solid var(--border-color)',borderRadius:8}}><div style={{fontWeight:700,fontSize:12}}>v{v.version_number}{v.is_current&&<div style={{fontSize:9,color:'var(--color-primary)'}}>{t('details.current_version_tag')}</div>}</div><div><div style={{fontSize:11,fontWeight:600}}>{v.original_name}</div><div style={{fontSize:10,color:'var(--text-secondary)'}}>{formatBytes(v.size)} · {v.changed_by||t('details.system')} · {v.created_at?new Date(v.created_at).toLocaleString():''}</div><div style={{fontSize:10,color:'var(--text-secondary)'}}>{v.change_note||t('details.no_change_note')}</div></div><div style={{display:'flex',gap:5}}><a className="btn btn-secondary" href={v.download_url} style={{padding:'5px 7px',fontSize:10}}>{t('details.download')}</a>{!v.is_current&&file.can_archive&&<button className="btn btn-secondary" disabled={lifecycleBusy} onClick={()=>restoreVersion(v)} style={{padding:'5px 7px',fontSize:10}}><RotateCcw size={12}/> {t('details.restore')}</button>}</div></div>)}</div>}<div style={{marginTop:12,paddingTop:12,borderTop:'1px solid var(--border-color)'}}><label className="form-label">{t('details.upload_new_version')}</label><input className="form-input" type="file" onChange={e=>setNewVersionFile(e.target.files?.[0]||null)}/><textarea className="form-input" rows={2} placeholder={t('details.change_note_placeholder')} value={changeNote} onChange={e=>setChangeNote(e.target.value)} style={{marginTop:7,resize:'vertical'}}/>{versionProgress&&<div className="setting-help" style={{marginTop:7,fontWeight:600}}>{versionProgress}</div>}<button className="btn btn-secondary" disabled={lifecycleBusy||!newVersionFile||changeNote.trim().length<3} onClick={uploadVersion} style={{width:'100%',marginTop:7}}><UploadCloud size={14}/> {lifecycleBusy?t('details.uploading_version'):t('details.upload_as_new')}</button></div></div>}

        {file.access_policy==='protected'&&file.can_preview&&file.access_expires_at&&<div className="admin-guide" style={{marginBottom:12}}><b>{t('details.temp_access')}</b> {t('details.temp_access_body',{when:new Date(file.access_expires_at).toLocaleString()})}</div>}
        {file.access_policy === 'protected' && file.access_request_status === 'pending' && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18, background: 'var(--bg-input)' }}><h3 style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}><ShieldAlert size={16} /> {t('details.request_pending')}</h3><p style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{t('details.request_pending_body')}</p></div>}
        {file.access_policy === 'protected' && file.can_request_access && file.access_request_status !== 'pending' && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18, background: 'var(--bg-input)' }}><h3 style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 10 }}><ShieldAlert size={16} /> {file.can_preview && !file.can_download ? t('details.request_download') : t('details.request_access')}</h3><textarea className="form-input" rows={3} value={reason} onChange={e => setReason(e.target.value)} placeholder={t('details.reason_placeholder')} style={{ resize: 'vertical' }} /><select className="form-select" value={level} onChange={e => setLevel(e.target.value as AccessLevel)} style={{ marginTop: 8 }}>{!file.can_preview && <option value="view">{t('details.view_only')}</option>}{file.download_allowed && <option value="download">{t('details.view_download')}</option>}</select><button className="btn btn-primary" style={{ width: '100%', marginTop: 10 }} disabled={requesting || reason.trim().length < 5} onClick={submitRequest}>{requesting ? t('details.submitting') : t('details.submit_request')}</button></div>}

        {file.can_manage_policy && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18 }}><h3 style={{ fontSize: 13, marginBottom: 10 }}>{t('details.owner_policy')}</h3><select className="form-select" value={policy} onChange={e => setPolicy(e.target.value as AccessPolicy)}><option value="public">{t('details.policy_public')}</option><option value="protected">{t('details.policy_protected')}</option><option value="private">{t('details.policy_private')}</option></select><label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 10, fontSize: 12 }}><input type="checkbox" checked={downloadAllowed} onChange={e => setDownloadAllowed(e.target.checked)} /> {t('details.downloads_allowed')}</label><button className="btn btn-secondary" style={{ width: '100%', marginTop: 10 }} disabled={savingPolicy || (policy === file.access_policy && downloadAllowed === file.download_allowed)} onClick={savePolicy}>{savingPolicy ? t('details.saving') : t('details.save_policy')}</button></div>}
        </div></details>


        <div style={{ display: 'flex', gap: 12, marginTop: 'auto' }}><button className="btn btn-primary" style={{ flex: 1 }} disabled={!file.can_download} onClick={() => onDownload(file.id)}><Download size={16} /> {file.can_download ? t('details.download_asset') : t('details.download_denied')}</button>{canDelete && <button className="btn btn-secondary" style={{ borderColor: 'var(--color-danger)', color: 'var(--color-danger)' }} onClick={() => onDelete(file.id)}><Trash2 size={16} /> {t('details.move_recycle')}</button>}</div>
    </div></div></div></>;
}
