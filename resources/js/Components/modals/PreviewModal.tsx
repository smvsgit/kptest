import { useEffect, useState } from 'react';
import { X, Download, Trash2, Headphones, LockKeyhole, ShieldAlert, History, UploadCloud, RotateCcw, Archive, AlertTriangle, Link2, RefreshCw, Plus } from 'lucide-react';
import type { AccessLevel, AccessPolicy, MediaFile, MediaFileVersion, Category, UploadSettings, SourceConnection, MediaSourceItem } from '../../types';

interface Props {
    file: MediaFile;
    categories: Category[];
    canDelete: boolean;
    uploadSettings: UploadSettings;
    sourceConnections: SourceConnection[];
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

export default function PreviewModal({ file, categories, canDelete, uploadSettings, sourceConnections, onClose, onDelete, onDownload, onRequestAccess, onUpdatePolicy, onLifecycleChanged }: Props) {
    const cat = categories.find(c => c.id === file.category_id);
    const sub = cat?.subcategories.find(s => s.id === file.subcategory_id);
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
    const [sources,setSources]=useState<MediaSourceItem[]>(file.source_items||[]);
    const [sourceBusy,setSourceBusy]=useState(false);
    const [sourceForm,setSourceForm]=useState({type:'youtube',label:'',locator:'',external_id:'',integration_connection_id:'',is_primary:false,is_enabled:true,repair_note:''});
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const loadVersions = async () => {
        if (!file.can_manage_lifecycle) return;
        setVersionLoading(true);
        try {
            const r = await fetch(`/files/${file.id}/versions`, {headers:{Accept:'application/json'}});
            const data = await r.json();
            if (!r.ok) throw new Error(data.message || 'Could not load version history.');
            setVersions(data.versions || []);
        } catch (e) { setLifecycleMessage(e instanceof Error ? e.message : 'Could not load version history.'); }
        finally { setVersionLoading(false); }
    };
    useEffect(() => { void loadVersions(); }, [file.id, file.can_manage_lifecycle]);

    const uploadVersion = async () => {
        if (!newVersionFile || changeNote.trim().length < 3) return;
        setLifecycleBusy(true); setLifecycleMessage(''); setVersionProgress('Preparing upload…');
        const chunkSize=Math.max(5,Math.min(20,uploadSettings.chunk_size_mb||10))*1024*1024; const totalChunks=Math.max(1,Math.ceil(newVersionFile.size/chunkSize)); const uploadId=crypto.randomUUID();
        try {
            for(let i=0;i<totalChunks;i++){
                const start=i*chunkSize,end=Math.min(start+chunkSize,newVersionFile.size); const fd=new FormData();
                fd.append('upload_id',uploadId);fd.append('chunk_index',String(i));fd.append('total_chunks',String(totalChunks));fd.append('total_size',String(newVersionFile.size));fd.append('is_last_chunk',i===totalChunks-1?'true':'false');fd.append('filename',newVersionFile.name);fd.append('chunk',newVersionFile.slice(start,end),`chunk-${i}`);
                if(i===totalChunks-1)fd.append('change_note',changeNote.trim());
                const r=await fetch(`/files/${file.id}/version-chunks`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'},body:fd}); const data=await r.json(); if(!r.ok)throw new Error(data.message||`Chunk ${i+1} failed.`);
                const sent=end; setVersionProgress(`${formatBytes(sent)} / ${formatBytes(newVersionFile.size)} · ${Math.round((sent/newVersionFile.size)*100)}%`);
                if(i===totalChunks-1)setLifecycleMessage(data.message||'New version uploaded.');
            }
            setNewVersionFile(null);setChangeNote('');await loadVersions();onLifecycleChanged();
        } catch(e){setLifecycleMessage(e instanceof Error?e.message:'Version upload failed.');try{await fetch(`/files/${file.id}/version-chunks/${uploadId}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}})}catch{}}
        finally {setLifecycleBusy(false);setVersionProgress('');}
    };

    const restoreVersion = async (version: MediaFileVersion) => {
        if (!confirm(`Restore version ${version.version_number} as a NEW current version? Existing history will be kept.`)) return;
        setLifecycleBusy(true); setLifecycleMessage('');
        try {
            const r = await fetch(`/files/${file.id}/versions/${version.id}/restore`, {method:'POST', headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({change_note:`Restored from version ${version.version_number}.`})});
            const data=await r.json(); if(!r.ok) throw new Error(data.message || 'Restore failed.');
            setLifecycleMessage(data.message || 'Version restored.'); await loadVersions(); onLifecycleChanged();
        } catch(e){ setLifecycleMessage(e instanceof Error ? e.message : 'Restore failed.'); }
        finally { setLifecycleBusy(false); }
    };

    const toggleArchive = async () => {
        const archive = file.asset_status !== 'archived';
        if (!confirm(archive ? 'Archive this asset? It will remain searchable with Archived status.' : 'Restore this asset from Archive?')) return;
        setLifecycleBusy(true);
        try {
            const r=await fetch(`/files/${file.id}/archive`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({archived:archive})});
            const data=await r.json(); if(!r.ok) throw new Error(data.message || 'Archive action failed.');
            onLifecycleChanged();
        } catch(e){ setLifecycleMessage(e instanceof Error?e.message:'Archive action failed.'); }
        finally { setLifecycleBusy(false); }
    };

    const checkSource = async (source:MediaSourceItem) => { setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}/check`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}}); const d=await r.json(); if(!r.ok)throw new Error(d.message||'Source check failed.'); setLifecycleMessage(`Source ${d.status}: ${d.message}`); onLifecycleChanged(); } catch(e){setLifecycleMessage(e instanceof Error?e.message:'Source check failed.')} finally{setSourceBusy(false)} };
    const saveSource = async () => { if(!sourceForm.locator.trim())return; setSourceBusy(true); try { const body={...sourceForm,integration_connection_id:sourceForm.integration_connection_id?Number(sourceForm.integration_connection_id):null}; const r=await fetch(`/files/${file.id}/sources`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)}); const d=await r.json(); if(!r.ok)throw new Error(d.message||'Could not add source.'); setLifecycleMessage(d.message||'Source added.'); setSourceForm({type:'youtube',label:'',locator:'',external_id:'',integration_connection_id:'',is_primary:false,is_enabled:true,repair_note:''}); onLifecycleChanged(); } catch(e){setLifecycleMessage(e instanceof Error?e.message:'Could not add source.')} finally{setSourceBusy(false)} };
    const repairSource = async (source:MediaSourceItem) => { const locator=prompt('Replace source locator / URL / relative path:',source.locator||''); if(locator===null||!locator.trim())return; const note=prompt('Repair note (optional):','Reference replaced after source health issue.')||''; setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type:source.type,label:source.label,locator,external_id:source.external_id,integration_connection_id:source.integration_connection_id,is_primary:source.is_primary,is_enabled:source.is_enabled,repair_note:note})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Repair failed.');setLifecycleMessage(d.message||'Source updated.');onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:'Repair failed.')}finally{setSourceBusy(false)} };
    const toggleSource = async (source:MediaSourceItem) => { setSourceBusy(true); try { const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type:source.type,label:source.label,locator:source.locator,external_id:source.external_id,integration_connection_id:source.integration_connection_id,is_primary:source.is_primary,is_enabled:!source.is_enabled,repair_note:source.repair_note})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Status change failed.');setLifecycleMessage(source.is_enabled?'Source disabled.':'Source enabled.');onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:'Status change failed.')}finally{setSourceBusy(false)} };
    const removeSource = async (source:MediaSourceItem) => { if(!confirm('Remove this source reference? The logical asset must retain at least one source.'))return;setSourceBusy(true);try{const r=await fetch(`/files/${file.id}/sources/${source.id}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.message||'Remove failed.');setLifecycleMessage(d.message||'Source removed.');onLifecycleChanged();}catch(e){setLifecycleMessage(e instanceof Error?e.message:'Remove failed.')}finally{setSourceBusy(false)}};

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

    const renderPreview = () => {
        if (!file.can_preview) {
            return <div className="access-locked-panel"><LockKeyhole size={58} /><div><h3 style={{ color: '#fff', marginBottom: 6 }}>Protected content is locked</h3><p style={{ color: '#cbd5e1', maxWidth: 360 }}>You can see the file name and basic metadata, but preview requires approval from the owner department.</p></div></div>;
        }
        const external=(file.source_items||[]).find(s=>s.is_primary&&s.status==='active'&&s.embed_url)||(file.source_items||[]).find(s=>s.status==='active'&&s.embed_url);
        if (external?.embed_url) return <iframe src={external.embed_url} title={file.name} style={{width:'100%',height:'50vh',border:0}} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowFullScreen/>;
        if (file.type === 'image') return file.thumbnail_url?<img src={file.thumbnail_url} alt={file.name} style={{ maxWidth: '100%', maxHeight: '50vh', objectFit: 'contain' }} />:<div className="access-locked-panel"><Link2 size={50}/><p>Image source has no local preview. Open an active linked source below.</p></div>;
        if (file.type === 'video') return file.video_url?<video controls autoPlay muted style={{ width: '100%', height: '100%', maxHeight: '50vh' }}><source src={file.video_url} />Your browser does not support the video tag.</video>:<div className="access-locked-panel"><Link2 size={50}/><p>Video source is external or unavailable. Use the Sources section below.</p></div>;
        if (file.type === 'audio') return <div className="modal-audio-player"><div className="audio-preview-icon"><Headphones size={32} /></div><div style={{ textAlign: 'center' }}><h4 style={{ fontWeight: 600, marginBottom: 4 }}>{file.name}</h4><span style={{ fontSize: 11, color: '#94a3b8' }}>Audio Track</span></div><audio controls autoPlay className="audio-element"><source src={file.audio_url || ''} /></audio></div>;
        const ext = file.name.split('.').pop()?.toUpperCase() || 'FILE';
        return <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20, color: '#ffffff', padding: 48 }}><div style={{ width: 72, height: 84, borderRadius: 8, background: 'var(--color-accent)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: '#1b2e2a', gap: 4 }}><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg><span style={{ fontSize: 11, fontWeight: 700 }}>{ext}</span></div><div style={{ textAlign: 'center' }}><h3 style={{ fontWeight: 600, marginBottom: 8 }}>{file.name}</h3><p style={{ fontSize: 12, color: '#94a3b8', marginBottom: 16 }}>Document preview</p>{file.document_url && <a href={file.document_url} target="_blank" rel="noopener noreferrer" style={{ fontSize: 13, color: 'var(--color-accent)', fontWeight: 600, textDecoration: 'underline' }}>Open in new tab ↗</a>}</div></div>;
    };

    const metadata = [
        ['Category:', cat?.name || 'Uncategorized'], ['Sub-category:', sub?.name || 'None'],
        ['Owner Department:', file.department?.name || 'Unassigned'], ['Access Policy:', file.access_policy.toUpperCase()],
        ['Year:', file.year || 'N/A'], ['Country:', file.country?.name || 'N/A'], ['State:', file.state?.name || 'N/A'], ['City / Location:', file.city?.name || 'N/A'],
        ['Mandir:', file.mandir?.name || 'N/A'], ['Event / Prasang:', file.event?.name || 'N/A'], ['Guruji / Person:', file.person?.name || 'N/A'], ['Language:', file.language?.name || 'N/A'],
        ['File Size:', formatBytes(file.size)], ['Resolution:', file.resolution || 'N/A'], ['Source Health:', file.source_health_status || 'unknown'], ['Source:', file.source_type || 'local'], ['Asset Status:', file.asset_status || 'active'],
        ['Current Version:', `v${file.current_version || 1}`], ['Upload Date:', new Date(file.created_at).toLocaleString()], ['Uploaded By:', file.uploader?.name || 'System'],
    ];

    return <div className="modal-overlay active" onClick={e => { if (e.target === e.currentTarget) onClose(); }}><div className="modal-container"><div className="modal-preview-pane" style={{position:'relative'}}>{renderPreview()}{file.can_preview&&file.watermark_enabled_effective&&<div aria-hidden="true" style={{position:'absolute',inset:0,pointerEvents:'none',display:'flex',alignItems:'center',justifyContent:'center',overflow:'hidden',opacity:.18,fontSize:34,fontWeight:800,transform:'rotate(-28deg)',color:'#fff',textShadow:'0 1px 2px #000'}}>{file.watermark_text||'Karyalay Portal'}</div>}</div><div className="modal-info-pane"><div className="modal-header"><div><h2 className="modal-title">{file.name}</h2><div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}><span className={`card-type-badge badge-${file.type}`} style={{ position: 'static', display: 'inline-block' }}>{file.type}</span><span className={`access-policy-badge access-${file.access_policy}`}>{file.access_policy}</span>{file.access_request_status && <span className="request-status-pill">Request: {file.access_request_status}</span>}</div></div><button className="modal-close-btn" onClick={onClose}><X size={20} /></button></div>
        <div className="metadata-grid">{metadata.map(([label, value]) => <div key={String(label)} className="metadata-row"><span className="metadata-label">{label}</span><span className="metadata-value">{value}</span></div>)}</div>
        {file.description&&<div className="form-group"><span className="form-label">Description:</span><div style={{fontSize:12,color:'var(--text-secondary)',marginTop:4}}>{file.description}</div></div>}<div className="form-group" style={{ marginBottom: 18 }}><span className="form-label">Associated Tags:</span><div className="card-tags" style={{ marginTop: 6 }}>{file.tags.map(tag => <span key={tag} className="card-tag" style={{ fontSize: 11, padding: '4px 8px' }}>{tag}</span>)}</div></div>

        <div style={{border:'1px solid var(--border-color)',borderRadius:'var(--radius-md)',padding:14,marginBottom:18}}><h3 style={{fontSize:13,display:'flex',gap:7,alignItems:'center',marginBottom:10}}><Link2 size={16}/> Sources · {(file.source_items||[]).length}</h3><div className="source-list">{(file.source_items||[]).map(src=><div className="source-row" key={src.id}><div className="source-row-main"><span className={`integration-status ${src.status}`}>{src.status}</span><div><div style={{fontSize:11,fontWeight:700}}>{src.label||src.type}{src.is_primary?' · PRIMARY':''}</div><div className="source-meta">{src.connection?.name||src.type} · Last check {src.last_checked_at?new Date(src.last_checked_at).toLocaleString():'Never'} · Last success {src.last_success_at?new Date(src.last_success_at).toLocaleString():'Never'}</div>{src.last_error&&<div className="source-meta" style={{color:'var(--color-danger)'}}>{src.last_error}</div>}</div></div><div style={{display:'flex',gap:5,flexWrap:'wrap',justifyContent:'flex-end'}}>{src.open_url&&file.can_preview&&<a className="btn btn-secondary" href={src.open_url} target="_blank" rel="noopener noreferrer" style={{padding:'5px 7px',fontSize:10}}>Open ↗</a>}<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>checkSource(src)} style={{padding:'5px 7px',fontSize:10}}><RefreshCw size={12}/> Retry Check</button>{file.can_manage_lifecycle&&<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>toggleSource(src)} style={{padding:'5px 7px',fontSize:10}}>{src.is_enabled?'Disable':'Enable'}</button>}{file.can_manage_lifecycle&&<button className="btn btn-secondary" disabled={sourceBusy} onClick={()=>repairSource(src)} style={{padding:'5px 7px',fontSize:10}}>Replace / Repair</button>}{file.can_manage_lifecycle&&<button className="queue-remove-btn" disabled={sourceBusy} onClick={()=>removeSource(src)}><Trash2 size={13}/></button>}</div></div>)}</div>{file.can_manage_lifecycle&&<div style={{marginTop:12,paddingTop:12,borderTop:'1px solid var(--border-color)'}}><div className="form-label">Add alternate source</div><div className="settings-grid"><select className="form-select" value={sourceForm.type} onChange={e=>setSourceForm({...sourceForm,type:e.target.value,integration_connection_id:''})}><option value="local">Local</option><option value="nas">NAS</option><option value="google-drive">Google Drive</option><option value="youtube">YouTube</option></select><select className="form-select" value={sourceForm.integration_connection_id} onChange={e=>setSourceForm({...sourceForm,integration_connection_id:e.target.value})}><option value="">No connection / Local</option>{sourceConnections.filter(c=>c.type===sourceForm.type).map(c=><option key={c.id} value={c.id}>{c.name} · {c.status}</option>)}</select><input className="form-input" placeholder="Label" value={sourceForm.label} onChange={e=>setSourceForm({...sourceForm,label:e.target.value})}/><input className="form-input" placeholder="URL / Drive ID / relative NAS path / local storage path" value={sourceForm.locator} onChange={e=>setSourceForm({...sourceForm,locator:e.target.value})}/></div><label style={{display:'flex',gap:7,alignItems:'center',fontSize:11,marginTop:8}}><input type="checkbox" checked={sourceForm.is_primary} onChange={e=>setSourceForm({...sourceForm,is_primary:e.target.checked})}/> Make primary source</label><button className="btn btn-secondary" disabled={sourceBusy||!sourceForm.locator.trim()} onClick={saveSource} style={{width:'100%',marginTop:8}}><Plus size={13}/> Add Source</button></div>}</div>

        {file.duplicate_of_id && <div style={{border:'1px solid var(--color-warning)',borderRadius:'var(--radius-md)',padding:12,marginBottom:16,background:'rgba(234,179,8,.08)'}}><div style={{display:'flex',gap:8,alignItems:'center',fontWeight:600,fontSize:12}}><AlertTriangle size={16}/> Potential duplicate detected</div><div style={{fontSize:11,color:'var(--text-secondary)',marginTop:4}}>SHA-256 matches asset #{file.duplicate_of_id}. Review before keeping both logical assets.</div></div>}

        {file.can_manage_lifecycle && <div style={{border:'1px solid var(--border-color)',borderRadius:'var(--radius-md)',padding:14,marginBottom:18}}><h3 style={{fontSize:13,display:'flex',gap:7,alignItems:'center',marginBottom:10}}><History size={16}/> Version History · Current v{file.current_version || 1}</h3>{lifecycleMessage&&<div className="admin-guide" style={{marginBottom:10}}>{lifecycleMessage}</div>}{versionLoading?<div style={{fontSize:12,color:'var(--text-secondary)'}}>Loading versions…</div>:<div style={{display:'grid',gap:7,maxHeight:180,overflowY:'auto'}}>{versions.map(v=><div key={v.id} style={{display:'grid',gridTemplateColumns:'52px 1fr auto',gap:8,alignItems:'center',padding:'8px 9px',border:'1px solid var(--border-color)',borderRadius:8}}><div style={{fontWeight:700,fontSize:12}}>v{v.version_number}{v.is_current&&<div style={{fontSize:9,color:'var(--color-primary)'}}>CURRENT</div>}</div><div><div style={{fontSize:11,fontWeight:600}}>{v.original_name}</div><div style={{fontSize:10,color:'var(--text-secondary)'}}>{formatBytes(v.size)} · {v.changed_by||'System'} · {v.created_at?new Date(v.created_at).toLocaleString():''}</div><div style={{fontSize:10,color:'var(--text-secondary)'}}>{v.change_note||'No change note'}</div></div><div style={{display:'flex',gap:5}}><a className="btn btn-secondary" href={v.download_url} style={{padding:'5px 7px',fontSize:10}}>Download</a>{!v.is_current&&file.can_archive&&<button className="btn btn-secondary" disabled={lifecycleBusy} onClick={()=>restoreVersion(v)} style={{padding:'5px 7px',fontSize:10}}><RotateCcw size={12}/> Restore</button>}</div></div>)}</div>}<div style={{marginTop:12,paddingTop:12,borderTop:'1px solid var(--border-color)'}}><label className="form-label">Upload New Version</label><input className="form-input" type="file" onChange={e=>setNewVersionFile(e.target.files?.[0]||null)}/><textarea className="form-input" rows={2} placeholder="Change note (required) — e.g. Corrected final poster text" value={changeNote} onChange={e=>setChangeNote(e.target.value)} style={{marginTop:7,resize:'vertical'}}/>{versionProgress&&<div className="setting-help" style={{marginTop:7,fontWeight:600}}>{versionProgress}</div>}<button className="btn btn-secondary" disabled={lifecycleBusy||!newVersionFile||changeNote.trim().length<3} onClick={uploadVersion} style={{width:'100%',marginTop:7}}><UploadCloud size={14}/> {lifecycleBusy?'Uploading version…':'Upload as New Version'}</button></div>{file.can_archive&&<button className="btn btn-secondary" disabled={lifecycleBusy} onClick={toggleArchive} style={{width:'100%',marginTop:9}}><Archive size={14}/> {file.asset_status==='archived'?'Restore from Archive':'Archive Asset'}</button>}</div>}

        {file.access_policy==='protected'&&file.can_preview&&file.access_expires_at&&<div className="admin-guide" style={{marginBottom:12}}><b>Temporary access active.</b> Expires {new Date(file.access_expires_at).toLocaleString()}. Protected downloads use a short-lived signed link and are revalidated at delivery time.</div>}
        {file.access_policy === 'protected' && file.access_request_status === 'pending' && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18, background: 'var(--bg-input)' }}><h3 style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}><ShieldAlert size={16} /> Access Request Pending</h3><p style={{ fontSize: 12, color: 'var(--text-secondary)' }}>Your request is waiting for owner-department approval.</p></div>}
        {file.access_policy === 'protected' && file.can_request_access && file.access_request_status !== 'pending' && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18, background: 'var(--bg-input)' }}><h3 style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 10 }}><ShieldAlert size={16} /> {file.can_preview && !file.can_download ? 'Request Download Access' : 'Request Access'}</h3><textarea className="form-input" rows={3} value={reason} onChange={e => setReason(e.target.value)} placeholder="Reason / purpose for access" style={{ resize: 'vertical' }} /><select className="form-select" value={level} onChange={e => setLevel(e.target.value as AccessLevel)} style={{ marginTop: 8 }}>{!file.can_preview && <option value="view">View Only</option>}{file.download_allowed && <option value="download">View + Download</option>}</select><button className="btn btn-primary" style={{ width: '100%', marginTop: 10 }} disabled={requesting || reason.trim().length < 5} onClick={submitRequest}>{requesting ? 'Submitting…' : 'Submit Access Request'}</button></div>}

        {file.can_manage_policy && <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: 14, marginBottom: 18 }}><h3 style={{ fontSize: 13, marginBottom: 10 }}>Owner Access Policy</h3><select className="form-select" value={policy} onChange={e => setPolicy(e.target.value as AccessPolicy)}><option value="public">Public — registered internal users</option><option value="protected">Protected — approval required</option><option value="private">Private — owner department only</option></select><label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 10, fontSize: 12 }}><input type="checkbox" checked={downloadAllowed} onChange={e => setDownloadAllowed(e.target.checked)} /> Downloads allowed for otherwise authorized users</label><button className="btn btn-secondary" style={{ width: '100%', marginTop: 10 }} disabled={savingPolicy || (policy === file.access_policy && downloadAllowed === file.download_allowed)} onClick={savePolicy}>{savingPolicy ? 'Saving…' : 'Save Access Policy'}</button></div>}

        <div style={{ display: 'flex', gap: 12, marginTop: 'auto' }}><button className="btn btn-primary" style={{ flex: 1 }} disabled={!file.can_download} onClick={() => onDownload(file.id)}><Download size={16} /> {file.can_download ? 'Download Asset' : 'Download Not Permitted'}</button>{canDelete && <button className="btn btn-secondary" style={{ borderColor: 'var(--color-danger)', color: 'var(--color-danger)' }} onClick={() => onDelete(file.id)}><Trash2 size={16} /> Move to Recycle Bin</button>}</div>
    </div></div></div>;
}
